package com.vitisstudio.plugins.firestore

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.Base64
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.google.android.gms.tasks.Task
import com.google.android.gms.tasks.Tasks
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseNetworkException
import com.google.firebase.FirebaseOptions
import com.google.firebase.FirebaseTooManyRequestsException
import com.google.firebase.Timestamp
import com.google.firebase.auth.FirebaseAuth
import com.google.firebase.auth.FirebaseAuthException
import com.google.firebase.auth.FirebaseUser
import com.google.firebase.firestore.AggregateSource
import com.google.firebase.firestore.Blob
import com.google.firebase.firestore.DocumentChange
import com.google.firebase.firestore.DocumentReference
import com.google.firebase.firestore.DocumentSnapshot
import com.google.firebase.firestore.FieldPath
import com.google.firebase.firestore.FieldValue
import com.google.firebase.firestore.Filter
import com.google.firebase.firestore.FirebaseFirestore
import com.google.firebase.firestore.FirebaseFirestoreException
import com.google.firebase.firestore.FirebaseFirestoreSettings
import com.google.firebase.firestore.GeoPoint
import com.google.firebase.firestore.ListenerRegistration
import com.google.firebase.firestore.MemoryCacheSettings
import com.google.firebase.firestore.MetadataChanges
import com.google.firebase.firestore.PersistentCacheSettings
import com.google.firebase.firestore.Query
import com.google.firebase.firestore.QuerySnapshot
import com.google.firebase.firestore.SetOptions
import com.google.firebase.firestore.SnapshotMetadata
import com.google.firebase.firestore.Source
import com.google.firebase.firestore.Transaction
import com.google.firebase.firestore.TransactionOptions
import com.google.firebase.firestore.WriteBatch
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.lang.ref.WeakReference
import java.util.UUID
import java.util.concurrent.ExecutionException
import java.util.concurrent.Executor
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException
import java.util.concurrent.locks.ReentrantLock
import kotlin.concurrent.withLock

// Shared machinery for the Firestore / FirebaseAuth bridge functions.
//
// NativePHP calls bridge functions on its PHP thread (never the main thread),
// so functions block on the SDK's Tasks with a timeout. Snapshot listeners run
// on a private executor; events to PHP are posted on the main looper.

private const val TAG = "NativeFirestore"

class NfsException(val code: String, message: String) : Exception(message)

internal object NfsErrors {
    fun from(error: Throwable?): NfsException {
        return when (error) {
            null -> NfsException("unknown", "Unknown error.")
            is NfsException -> error
            is ExecutionException -> from(error.cause)
            is FirebaseFirestoreException -> NfsException(
                error.code.name.lowercase().replace('_', '-'),
                error.message ?: error.code.name,
            )
            is FirebaseAuthException -> NfsException(
                error.errorCode.removePrefix("ERROR_").lowercase().replace('_', '-'),
                error.message ?: error.errorCode,
            )
            is FirebaseNetworkException -> NfsException("network-request-failed", error.message ?: "Network error.")
            is FirebaseTooManyRequestsException -> NfsException("too-many-requests", error.message ?: "Too many requests.")
            is TimeoutException -> NfsException("deadline-exceeded", error.message ?: "Timed out.")
            is IllegalArgumentException -> NfsException("invalid-argument", error.message ?: "Invalid argument.")
            is IllegalStateException -> NfsException("failed-precondition", error.message ?: "Invalid state.")
            else -> NfsException("unknown", error.message ?: error.javaClass.simpleName)
        }
    }

    fun invalid(message: String) = NfsException("invalid-argument", message)
}

// MARK: - Bridge envelope & JSON

internal object NfsBridge {
    private val mainHandler = Handler(Looper.getMainLooper())

    fun handle(parameters: Map<String, Any>, body: (Map<String, Any?>) -> Map<String, Any?>): Map<String, Any> {
        return try {
            val payload = decodePayload(parameters)
            val result = NfsJson.toJson(body(payload)) as JSONObject
            BridgeResponse.success(mapOf("result" to result.toString()))
        } catch (error: Throwable) {
            val failure = NfsErrors.from(error)
            Log.w(TAG, "${failure.code}: ${failure.message}")
            BridgeResponse.error(failure.code, failure.message ?: failure.code)
        }
    }

    @Suppress("UNCHECKED_CAST")
    private fun decodePayload(parameters: Map<String, Any>): Map<String, Any?> {
        val raw = parameters["payload"] as? String
            ?: return parameters.mapValues { NfsJson.fromJson(it.value) }

        return NfsJson.fromJson(JSONObject(raw)) as? Map<String, Any?>
            ?: throw NfsErrors.invalid("Bridge payload is not a JSON object.")
    }

    /** Deliver an event to PHP (NativeComponent #[On] handlers / Livewire). */
    fun dispatch(event: String, payload: Map<String, Any?>) {
        val activity = NfsRuntime.activity() ?: run {
            Log.w(TAG, "No activity to dispatch $event")
            return
        }
        val json = (NfsJson.toJson(payload) as JSONObject).toString()
        mainHandler.post { NativeActionCoordinator.dispatchEvent(activity, event, json) }
    }
}

internal object NfsJson {
    fun fromJson(value: Any?): Any? = when (value) {
        null, JSONObject.NULL -> null
        is JSONObject -> {
            val map = LinkedHashMap<String, Any?>()
            val keys = value.keys()
            while (keys.hasNext()) {
                val key = keys.next()
                map[key] = fromJson(value.opt(key))
            }
            map
        }
        is JSONArray -> (0 until value.length()).map { fromJson(value.opt(it)) }
        is Boolean, is String -> value
        is Double -> value
        is Float -> value.toDouble()
        is java.math.BigDecimal -> value.toDouble()
        is Int -> value.toLong()
        is Long -> value
        is Short -> value.toLong()
        is Byte -> value.toLong()
        is java.math.BigInteger -> value.toLong()
        is Number -> value.toDouble()
        is Map<*, *> -> value.entries.associate { it.key.toString() to fromJson(it.value) }
        is List<*> -> value.map { fromJson(it) }
        else -> value.toString()
    }

    fun toJson(value: Any?): Any = when (value) {
        null -> JSONObject.NULL
        is Map<*, *> -> {
            val json = JSONObject()
            for ((key, child) in value) {
                json.put(key.toString(), toJson(child))
            }
            json
        }
        is List<*> -> {
            val json = JSONArray()
            for (child in value) {
                json.put(toJson(child))
            }
            json
        }
        is Double -> if (value.isNaN() || value.isInfinite()) toJson(NfsCodec.taggedDouble(value)) else value
        is Float -> toJson(value.toDouble())
        is Int -> value.toLong()
        else -> value
    }
}

// MARK: - Runtime

internal object NfsRuntime {
    private const val DEMO_APP_ID = "1:123456789000:android:0000000000000000"
    private const val DEMO_API_KEY = "AIzaSyDemoKeyForTheFirebaseEmulators000"
    private const val CONFIG_ASSET = "nativephp-firestore/google-services.json"

    val callbackExecutor: Executor = Executors.newSingleThreadExecutor()

    private val lock = ReentrantLock()
    private var activityRef: WeakReference<FragmentActivity>? = null
    private var firestore: FirebaseFirestore? = null
    private var app: FirebaseApp? = null
    private var source = ""
    private var emulatorEnabled = false

    @Volatile var timeoutMillis: Long = 15_000
        private set

    @Volatile var serverTimestamps: DocumentSnapshot.ServerTimestampBehavior = DocumentSnapshot.ServerTimestampBehavior.NONE
        private set

    fun attach(activity: FragmentActivity) {
        activityRef = WeakReference(activity)
    }

    fun activity(): FragmentActivity? = activityRef?.get()

    fun configure(context: Context, payload: Map<String, Any?>): Map<String, Any?> = lock.withLock {
        firestore?.let { return status(true) }

        (payload["timeout"] as? Number)?.toDouble()?.takeIf { it > 0 }?.let { timeoutMillis = (it * 1000).toLong() }

        serverTimestamps = when (payload["serverTimestamps"] as? String) {
            "estimate" -> DocumentSnapshot.ServerTimestampBehavior.ESTIMATE
            "previous" -> DocumentSnapshot.ServerTimestampBehavior.PREVIOUS
            else -> DocumentSnapshot.ServerTimestampBehavior.NONE
        }

        val firebaseApp = FirebaseApp.getApps(context).firstOrNull { it.name == FirebaseApp.DEFAULT_APP_NAME }
            ?.also { source = "existing-app" }
            ?: run {
                @Suppress("UNCHECKED_CAST")
                val (options, origin) = resolveOptions(context, payload["options"] as? Map<String, Any?> ?: emptyMap())
                source = origin
                FirebaseApp.initializeApp(context, options)
            }

        val database = (payload["database"] as? String)?.takeIf { it.isNotEmpty() } ?: "(default)"
        val instance = if (database == "(default)") {
            FirebaseFirestore.getInstance(firebaseApp)
        } else {
            FirebaseFirestore.getInstance(firebaseApp, database)
        }

        @Suppress("UNCHECKED_CAST")
        val emulator = payload["emulator"] as? Map<String, Any?> ?: emptyMap()
        emulatorEnabled = emulator["enabled"] as? Boolean ?: false

        @Suppress("UNCHECKED_CAST")
        val persistence = payload["persistence"] as? Map<String, Any?> ?: emptyMap()

        try {
            if (emulatorEnabled) {
                val host = (emulator["firestoreHost"] as? String)?.takeIf { it.isNotEmpty() } ?: "10.0.2.2"
                val port = (emulator["firestorePort"] as? Number)?.toInt() ?: 8080
                instance.useEmulator(host, port)

                val authHost = (emulator["authHost"] as? String)?.takeIf { it.isNotEmpty() } ?: "10.0.2.2"
                val authPort = (emulator["authPort"] as? Number)?.toInt() ?: 9099
                FirebaseAuth.getInstance(firebaseApp).useEmulator(authHost, authPort)
            }

            val cache = if (persistence["enabled"] as? Boolean != false) {
                val requested = (persistence["cacheSizeBytes"] as? Number)?.toLong() ?: 104_857_600L
                val size = if (requested < 0) FirebaseFirestoreSettings.CACHE_SIZE_UNLIMITED else maxOf(requested, 1_048_576L)
                PersistentCacheSettings.newBuilder().setSizeBytes(size).build()
            } else {
                MemoryCacheSettings.newBuilder().build()
            }

            instance.firestoreSettings = FirebaseFirestoreSettings.Builder()
                .setLocalCacheSettings(cache)
                .build()
        } catch (error: IllegalStateException) {
            throw NfsException(
                "failed-precondition",
                "Firestore was used before the plugin could configure it (${error.message}). Settings were not applied.",
            )
        }

        app = firebaseApp
        firestore = instance

        Log.i(TAG, "configured (project ${firebaseApp.options.projectId}, source $source, emulator $emulatorEnabled)")

        status(false)
    }

    fun db(): FirebaseFirestore = lock.withLock {
        firestore ?: throw NfsException("not-configured", "Firestore is not configured yet. Call Firestore.Configure first.")
    }

    fun auth(): FirebaseAuth = lock.withLock {
        val configuredApp = app ?: throw NfsException("not-configured", "Firebase is not configured yet. Call Firestore.Configure first.")
        FirebaseAuth.getInstance(configuredApp)
    }

    fun status(alreadyConfigured: Boolean = false): Map<String, Any?> = mapOf(
        "configured" to (firestore != null),
        "alreadyConfigured" to alreadyConfigured,
        "projectId" to app?.options?.projectId,
        "source" to source,
        "emulator" to emulatorEnabled,
        "platform" to "android",
    )

    fun <T> await(task: Task<T>, what: String): T {
        if (Looper.myLooper() == Looper.getMainLooper()) {
            throw NfsException("failed-precondition", "$what cannot block the main thread.")
        }
        try {
            return Tasks.await(task, timeoutMillis, TimeUnit.MILLISECONDS)
        } catch (error: TimeoutException) {
            throw NfsException("deadline-exceeded", "$what did not complete within ${timeoutMillis / 1000}s.")
        } catch (error: ExecutionException) {
            throw NfsErrors.from(error.cause)
        }
    }

    private fun resolveOptions(context: Context, explicit: Map<String, Any?>): Pair<FirebaseOptions, String> {
        fun string(key: String): String? = (explicit[key] as? String)?.takeIf { it.isNotEmpty() }

        val projectId = string("projectId")
        if (projectId != null) {
            val isDemo = projectId.startsWith("demo-")
            val appId = string("androidAppId") ?: (if (isDemo) DEMO_APP_ID else null)
            val apiKey = string("apiKey") ?: (if (isDemo) DEMO_API_KEY else null)

            if (appId == null || apiKey == null) {
                throw NfsException(
                    "not-configured",
                    "FIREBASE_PROJECT_ID is set but FIREBASE_ANDROID_APP_ID / FIREBASE_API_KEY are missing (only demo-* projects may omit them).",
                )
            }

            val builder = FirebaseOptions.Builder()
                .setApplicationId(appId)
                .setApiKey(apiKey)
                .setProjectId(projectId)
                .setGcmSenderId(string("senderId") ?: appId.split(":").getOrNull(1) ?: "0")
            string("storageBucket")?.let { builder.setStorageBucket(it) }

            return builder.build() to (if (isDemo) "demo-options" else "options")
        }

        val bundled = try {
            context.assets.open(CONFIG_ASSET).bufferedReader().use { it.readText() }
        } catch (error: Exception) {
            null
        }

        if (bundled != null) {
            return parseGoogleServices(bundled, context.packageName) to "google-services.json"
        }

        // Apps built with the Google Services Gradle plugin carry the values as resources.
        FirebaseOptions.fromResource(context)?.let { return it to "resources" }

        throw NfsException(
            "not-configured",
            "Firebase is not configured: put google-services.json in the project root (copied by the plugin's build hook) " +
                "or set FIREBASE_PROJECT_ID (a demo-* project id needs nothing else, for the Emulator Suite).",
        )
    }

    private fun parseGoogleServices(json: String, packageName: String): FirebaseOptions {
        val root = JSONObject(json)
        val project = root.getJSONObject("project_info")
        val clients = root.getJSONArray("client")

        var client: JSONObject? = null
        for (i in 0 until clients.length()) {
            val candidate = clients.getJSONObject(i)
            val pkg = candidate.optJSONObject("client_info")?.optJSONObject("android_client_info")?.optString("package_name")
            if (pkg == packageName) {
                client = candidate
                break
            }
        }

        if (client == null) {
            if (clients.length() == 0) {
                throw NfsException("not-configured", "google-services.json has no Android client.")
            }
            Log.w(TAG, "google-services.json has no client for $packageName; using the first client.")
            client = clients.getJSONObject(0)
        }

        val appId = client.getJSONObject("client_info").getString("mobilesdk_app_id")
        val apiKey = client.optJSONArray("api_key")?.optJSONObject(0)?.optString("current_key")
            ?: throw NfsException("not-configured", "google-services.json has no api_key.")

        val builder = FirebaseOptions.Builder()
            .setApplicationId(appId)
            .setApiKey(apiKey)
            .setProjectId(project.getString("project_id"))
            .setGcmSenderId(project.optString("project_number"))
        project.optString("storage_bucket").takeIf { it.isNotEmpty() }?.let { builder.setStorageBucket(it) }

        return builder.build()
    }
}

// MARK: - Paths

internal object NfsPaths {
    fun segments(path: String): List<String> {
        val trimmed = path.trim('/')
        val parts = trimmed.split("/")
        if (trimmed.isEmpty() || parts.any { it.isEmpty() || it == "." || it == ".." }) {
            throw NfsErrors.invalid("Invalid Firestore path [$path].")
        }
        return parts
    }

    fun document(path: String, db: FirebaseFirestore): DocumentReference {
        val parts = segments(path)
        if (parts.size % 2 != 0) throw NfsErrors.invalid("[$path] is not a document path.")
        return db.document(parts.joinToString("/"))
    }

    fun collection(path: String, db: FirebaseFirestore): Query {
        val parts = segments(path)
        if (parts.size % 2 != 1) throw NfsErrors.invalid("[$path] is not a collection path.")
        return db.collection(parts.joinToString("/"))
    }

    fun fieldSegments(field: String): List<String> {
        val parts = field.split(".")
        if (field.isEmpty() || parts.any { it.isEmpty() }) throw NfsErrors.invalid("Invalid field path [$field].")
        if (field.any { it in "~*/[]" }) throw NfsErrors.invalid("Field path [$field] contains a reserved character (~ * / [ ]).")
        return parts
    }

    fun fieldPath(field: String): FieldPath =
        if (field == "__name__") FieldPath.documentId() else FieldPath.of(*fieldSegments(field).toTypedArray())

    /** Firestore reserves field names that begin and end with "__". */
    fun guardWritableName(name: String) {
        if (name.length >= 2 && name.startsWith("__") && name.endsWith("__")) {
            throw NfsErrors.invalid("Field name [$name] is reserved: Firestore field names cannot begin and end with \"__\".")
        }
    }
}

// MARK: - Codec

internal enum class NfsContext {
    SET, MERGE, UPDATE, QUERY;

    val isWrite: Boolean get() = this != QUERY
}

/** The range Firestore timestamps cover: 0001-01-01T00:00:00Z .. 9999-12-31T23:59:59Z. */
internal val NFS_TIMESTAMP_SECONDS_RANGE = -62_135_596_800L..253_402_300_799L

internal object NfsCodec {
    // org.json writes 1.0 as `1` and -0.0 as `-0`, which PHP reads back as
    // integers, so whole-valued doubles travel tagged like NaN and the
    // infinities do. A double with a fraction stays a plain JSON number: its
    // text always carries a "." or an exponent, so PHP reads it as a float.
    fun encodeDouble(value: Double): Any = when {
        value.isNaN() || value.isInfinite() -> taggedDouble(value)
        value == Math.rint(value) -> mapOf("__type" to "double", "value" to value.toString())
        else -> value
    }

    fun taggedDouble(value: Double): Map<String, Any?> = mapOf(
        "__type" to "double",
        "value" to when {
            value.isNaN() -> "NaN"
            value > 0 -> "Infinity"
            else -> "-Infinity"
        },
    )

    // `inArray`: the direct parent is an array (nested arrays are illegal).
    // `underArray`: any ancestor is an array (sentinels are illegal); survives maps.
    fun decode(value: Any?, db: FirebaseFirestore, context: NfsContext, depth: Int = 0, inArray: Boolean = false, underArray: Boolean = false): Any? {
        if (depth >= 20) throw NfsErrors.invalid("Data is nested too deeply (Firestore allows 20 levels).")

        return when (value) {
            null -> null
            is Boolean, is String, is Long, is Double -> value
            is Number -> if (value is Float) value.toDouble() else value.toLong()
            is List<*> -> {
                if (inArray) throw NfsErrors.invalid("Firestore does not support arrays directly inside arrays.")
                value.map { decode(it, db, context, depth + 1, inArray = true, underArray = true) }
            }
            is Map<*, *> -> {
                val type = value["__type"] as? String
                if (type != null) {
                    decodeTagged(type, value, db, context, depth, underArray || inArray)
                } else {
                    val result = LinkedHashMap<String, Any?>()
                    for ((key, child) in value) {
                        val name = key.toString()
                        if (name.isEmpty()) throw NfsErrors.invalid("Document field names cannot be empty.")
                        if (context.isWrite) NfsPaths.guardWritableName(name)
                        result[name] = decode(child, db, context, depth + 1, underArray = underArray || inArray)
                    }
                    result
                }
            }
            else -> throw NfsErrors.invalid("Unsupported value ${value.javaClass.simpleName}.")
        }
    }

    @Suppress("UNCHECKED_CAST")
    fun decodeMap(value: Any?, db: FirebaseFirestore, context: NfsContext): Map<String, Any?> {
        if (value is List<*> && value.isEmpty()) return emptyMap()
        if (value !is Map<*, *>) throw NfsErrors.invalid("Document data must be a map.")
        return decode(value, db, context) as? Map<String, Any?> ?: throw NfsErrors.invalid("Document data must be a map.")
    }

    private fun decodeTagged(type: String, map: Map<*, *>, db: FirebaseFirestore, context: NfsContext, depth: Int, underArray: Boolean): Any? {
        return when (type) {
            "timestamp" -> {
                val seconds = map["seconds"] as? Number
                if (seconds == null || seconds.toDouble() !in -62_135_596_800.0..253_402_300_799.0 || seconds.toLong() !in NFS_TIMESTAMP_SECONDS_RANGE) {
                    throw NfsErrors.invalid("Timestamp seconds must fall between 0001-01-01T00:00:00Z and 9999-12-31T23:59:59Z.")
                }
                val nanos = (map["nanoseconds"] as? Number)?.toLong() ?: 0L
                if (nanos !in 0L..999_999_999L) throw NfsErrors.invalid("Timestamp nanoseconds out of range.")
                Timestamp(seconds.toLong(), nanos.toInt())
            }
            "geopoint" -> {
                val latitude = (map["latitude"] as? Number)?.toDouble() ?: 0.0
                val longitude = (map["longitude"] as? Number)?.toDouble() ?: 0.0
                if (latitude !in -90.0..90.0 || longitude !in -180.0..180.0) {
                    throw NfsErrors.invalid("GeoPoint coordinates out of range.")
                }
                GeoPoint(latitude, longitude)
            }
            "reference" -> NfsPaths.document(map["path"] as? String ?: "", db)
            "bytes" -> Blob.fromBytes(Base64.decode(map["base64"] as? String ?: "", Base64.DEFAULT))
            "double" -> when (map["value"]) {
                "NaN" -> Double.NaN
                "Infinity" -> Double.POSITIVE_INFINITY
                "-Infinity" -> Double.NEGATIVE_INFINITY
                else -> when (val raw = map["value"]) {
                    is String -> raw.toDoubleOrNull() ?: throw NfsErrors.invalid("Invalid value for a tagged double.")
                    is Number -> raw.toDouble()
                    else -> throw NfsErrors.invalid("Invalid value for a tagged double.")
                }
            }
            "fieldvalue" -> decodeSentinel(map, db, context, depth, underArray)
            // Our own tag for empty / list-like maps (see the encoder), echoed back by a
            // snapshot round trip (e.g. the JS wrapper's set(path, snapshot.data)).
            "map" -> {
                val entries: Map<*, *> = when (val raw = map["value"]) {
                    is Map<*, *> -> raw
                    is List<*> -> if (raw.isEmpty()) emptyMap<String, Any?>() else throw NfsErrors.invalid("Invalid value for a tagged map.")
                    else -> throw NfsErrors.invalid("Invalid value for a tagged map.")
                }
                entries.entries.associate {
                    if (context.isWrite) NfsPaths.guardWritableName(it.key.toString())
                    it.key.toString() to decode(it.value, db, context, depth + 1, underArray = underArray)
                }
            }
            else -> map.entries.associate {
                if (context.isWrite) NfsPaths.guardWritableName(it.key.toString())
                it.key.toString() to decode(it.value, db, context, depth + 1, underArray = underArray)
            }
        }
    }

    private fun decodeSentinel(map: Map<*, *>, db: FirebaseFirestore, context: NfsContext, depth: Int, underArray: Boolean): FieldValue {
        val op = map["op"] as? String ?: ""
        if (context == NfsContext.QUERY) throw NfsErrors.invalid("FieldValue.$op() cannot be used in a query.")
        if (underArray) throw NfsErrors.invalid("FieldValue.$op() cannot be used inside an array.")

        return when (op) {
            "serverTimestamp" -> FieldValue.serverTimestamp()
            "increment" -> when (val by = map["value"]) {
                is Double -> FieldValue.increment(by)
                is Float -> FieldValue.increment(by.toDouble())
                is Number -> FieldValue.increment(by.toLong())
                else -> throw NfsErrors.invalid("FieldValue.increment() needs a number.")
            }
            "arrayUnion", "arrayRemove" -> {
                val elements = ((map["value"] as? List<*>) ?: emptyList<Any?>())
                    .map { decode(it, db, NfsContext.QUERY, depth + 1, inArray = true, underArray = true) }
                    .toTypedArray()
                if (op == "arrayUnion") FieldValue.arrayUnion(*elements) else FieldValue.arrayRemove(*elements)
            }
            "delete" -> when {
                context == NfsContext.MERGE -> FieldValue.delete()
                context == NfsContext.UPDATE && depth <= 1 -> FieldValue.delete()
                context == NfsContext.UPDATE -> throw NfsErrors.invalid("FieldValue.delete() can only appear at the top level of update() data.")
                else -> throw NfsErrors.invalid("FieldValue.delete() can only be used with update() or set() with merge.")
            }
            else -> throw NfsErrors.invalid("Unknown field value [$op].")
        }
    }

    fun encode(value: Any?): Any? = when (value) {
        null -> null
        is Timestamp -> mapOf("__type" to "timestamp", "seconds" to value.seconds, "nanoseconds" to value.nanoseconds)
        is GeoPoint -> mapOf("__type" to "geopoint", "latitude" to value.latitude, "longitude" to value.longitude)
        is DocumentReference -> mapOf("__type" to "reference", "path" to value.path)
        is Blob -> mapOf("__type" to "bytes", "base64" to Base64.encodeToString(value.toBytes(), Base64.NO_WRAP))
        is Boolean, is String, is Long -> value
        is Int -> value.toLong()
        is Double -> encodeDouble(value)
        is Float -> encode(value.toDouble())
        is Number -> value.toLong()
        is List<*> -> value.map { encode(it) }
        is Map<*, *> -> {
            val encoded = encodeMap(value)
            // PHP decodes an empty map, or one keyed exactly "0".."n-1", as a
            // list, so those travel tagged and stay maps on the PHP side.
            if (isListLike(encoded)) mapOf("__type" to "map", "value" to encoded) else encoded
        }
        else -> value.toString()
    }

    /** Document data: always a map, so the top level is never tagged. */
    fun encodeMap(map: Map<*, *>): Map<String, Any?> = map.entries.associate { it.key.toString() to encode(it.value) }

    private fun isListLike(map: Map<String, Any?>): Boolean =
        map.keys == (0 until map.size).map { it.toString() }.toSet()

    fun document(snapshot: DocumentSnapshot): Map<String, Any?> = mapOf(
        "id" to snapshot.id,
        "path" to snapshot.reference.path,
        "exists" to snapshot.exists(),
        "data" to if (snapshot.exists()) encodeMap(snapshot.getData(NfsRuntime.serverTimestamps) ?: emptyMap<String, Any?>()) else null,
        "metadata" to metadata(snapshot.metadata) + ("pendingServerTimestamps" to pendingServerTimestamps(snapshot)),
    )

    /**
     * Fields holding a server timestamp the backend has not set yet: null
     * without an estimate, a timestamp with one. PHP needs these to refuse
     * such a snapshot as a query cursor.
     */
    fun pendingServerTimestamps(snapshot: DocumentSnapshot): List<String> {
        if (!snapshot.exists() || !snapshot.metadata.hasPendingWrites()) return emptyList()
        val estimated = snapshot.getData(DocumentSnapshot.ServerTimestampBehavior.ESTIMATE) ?: return emptyList()
        val raw = snapshot.getData(DocumentSnapshot.ServerTimestampBehavior.NONE) ?: return emptyList()

        val pending = mutableListOf<String>()
        fun walk(estimated: Map<*, *>, raw: Map<*, *>, prefix: String) {
            for ((key, value) in estimated) {
                val path = if (prefix.isEmpty()) key.toString() else "$prefix.$key"
                val rawValue = raw[key]
                if (value is Timestamp && raw.containsKey(key) && rawValue == null) {
                    pending.add(path)
                } else if (value is Map<*, *> && rawValue is Map<*, *>) {
                    walk(value, rawValue, path)
                }
            }
        }
        walk(estimated, raw, "")
        return pending.sorted()
    }

    fun query(snapshot: QuerySnapshot): Map<String, Any?> = mapOf(
        "documents" to snapshot.documents.map { document(it) },
        "changes" to snapshot.documentChanges.map { change ->
            mapOf(
                "type" to when (change.type) {
                    DocumentChange.Type.ADDED -> "added"
                    DocumentChange.Type.MODIFIED -> "modified"
                    DocumentChange.Type.REMOVED -> "removed"
                },
                "document" to document(change.document),
                "oldIndex" to change.oldIndex,
                "newIndex" to change.newIndex,
            )
        },
        "metadata" to metadata(snapshot.metadata),
    )

    fun metadata(metadata: SnapshotMetadata): Map<String, Any?> = mapOf(
        "fromCache" to metadata.isFromCache,
        "hasPendingWrites" to metadata.hasPendingWrites(),
    )
}

// MARK: - Queries

internal object NfsQueryBuilder {
    private const val MAX_DISJUNCTIONS = 30
    private const val MAX_NOT_IN_VALUES = 10

    /**
     * The SDK itself only refuses a second != or not-in, or not-in beside !=,
     * in or array-contains-any, anywhere in the query (iOS with an uncatchable
     * exception). The rest are server limits judged per disjunction of the
     * query's disjunctive normal form (see normalForm), plus no not-in beside
     * an OR; checking them here keeps the offline cache, the server and the
     * PHP fake in agreement.
     */
    private class Stats {
        var disjunctive = 0
        var notIn = 0
        var notEqual = 0
        var hasOr = false
        val inequalityFields = mutableListOf<String>()

        fun validate() {
            if (notIn > 1) throw NfsErrors.invalid("A query can use at most one not-in filter.")
            if (notEqual > 1) throw NfsErrors.invalid("A query can use at most one != filter.")
            if (notIn > 0 && (notEqual > 0 || disjunctive > 0 || hasOr)) {
                throw NfsErrors.invalid("not-in cannot be combined with !=, in, array-contains-any or OR filters.")
            }
        }
    }

    private class OrderClause(val field: String, val path: FieldPath, val descending: Boolean)

    private class CursorBound(val values: Array<Any?>, val inclusive: Boolean, val isSnapshot: Boolean)

    /**
     * The whole query spec (filters including nested OR/AND, ordering, limit,
     * cursors) is decoded and validated before any SDK query call, mirroring
     * iOS, where the SDK reports a bad clause with an uncatchable exception.
     * PHP validates too, but the JavaScript bridge wrappers reach this directly.
     */
    @Suppress("UNCHECKED_CAST")
    fun build(wire: Map<String, Any?>, db: FirebaseFirestore): Query {
        val path = wire["path"] as? String ?: ""
        val isGroup = wire["collectionGroup"] as? Boolean ?: false
        val collectionPath = if (isGroup) null else NfsPaths.segments(path).joinToString("/")

        if (isGroup) {
            if (path.isEmpty() || path.contains("/")) throw NfsErrors.invalid("Invalid collection group id [$path].")
        } else if (collectionPath!!.split("/").size % 2 != 1) {
            throw NfsErrors.invalid("[$path] is not a collection path.")
        }

        val rawFilters = (wire["filters"] ?: emptyList<Any?>()) as? List<*> ?: throw NfsErrors.invalid("Query filters must be a list.")
        val stats = Stats()
        val filters = rawFilters.map {
            buildFilter(it as? Map<String, Any?> ?: throw NfsErrors.invalid("Each query filter must be a map."), db, collectionPath, stats)
        }
        stats.validate()
        // The top-level filters are an implicit AND.
        val (terms, arrayContains) = normalForm(mapOf("composite" to "and", "filters" to rawFilters))
        if (terms > MAX_DISJUNCTIONS) {
            throw NfsErrors.invalid("A query can have at most $MAX_DISJUNCTIONS disjunctions once its OR, in and array-contains-any filters are expanded.")
        }
        if (arrayContains > 1) {
            throw NfsErrors.invalid("Each disjunction of a query can use at most one array-contains or array-contains-any filter.")
        }

        val rawOrder = (wire["orderBy"] ?: emptyList<Any?>()) as? List<*> ?: throw NfsErrors.invalid("Query orderBy must be a list.")
        val explicit = rawOrder.map {
            val order = it as? Map<String, Any?> ?: throw NfsErrors.invalid("Each orderBy clause must be a map.")
            val field = order["field"] as? String ?: ""
            val direction = order["direction"] as? String ?: "asc"
            if (direction != "asc" && direction != "desc") throw NfsErrors.invalid("Order direction must be asc or desc.")
            OrderClause(field, NfsPaths.fieldPath(field), direction == "desc")
        }

        var limit: Pair<Long, Boolean>? = null
        wire["limit"]?.let { raw ->
            val number = raw as? Number
            if (number == null || number.toDouble() < 1 || number.toDouble() > Int.MAX_VALUE) {
                throw NfsErrors.invalid("A query limit must be a whole number from 1 to ${Int.MAX_VALUE}.")
            }
            val isLast = wire["limitType"] == "last"
            if (isLast && explicit.isEmpty()) throw NfsErrors.invalid("limitToLast() requires an orderBy() clause.")
            limit = number.toLong() to isLast
        }

        val normalized = normalizedOrder(explicit, stats.inequalityFields)
        val start = (wire["startAt"] as? Map<String, Any?>)?.let { cursorBound(it, explicit, normalized, db, collectionPath) }
        val end = (wire["endAt"] as? Map<String, Any?>)?.let { cursorBound(it, explicit, normalized, db, collectionPath) }

        // A snapshot cursor positions on the full normalized ordering, but value
        // cursors may only cover explicit orderBy clauses, so the implicit ones
        // are spelled out. The SDK normalizes to this same ordering anyway.
        val ordering = if (start?.isSnapshot == true || end?.isSnapshot == true) normalized else explicit

        // Everything is valid: build the SDK query.
        var query: Query = if (isGroup) db.collectionGroup(path) else db.collection(collectionPath!!)
        for (filter in filters) query = query.where(filter)
        for (order in ordering) {
            query = query.orderBy(order.path, if (order.descending) Query.Direction.DESCENDING else Query.Direction.ASCENDING)
        }
        limit?.let { (count, last) -> query = if (last) query.limitToLast(count) else query.limit(count) }
        start?.let { query = if (it.inclusive) query.startAt(*it.values) else query.startAfter(*it.values) }
        end?.let { query = if (it.inclusive) query.endAt(*it.values) else query.endBefore(*it.values) }

        return query
    }

    /**
     * The ordering the SDK really applies: explicit clauses, then inequality
     * fields not already ordered (in field-path order), then the document id,
     * the implicit ones following the last explicit direction.
     */
    private fun normalizedOrder(explicit: List<OrderClause>, inequalityFields: List<String>): List<OrderClause> {
        val ordered = explicit.map { it.field }.toSet()
        val descending = explicit.lastOrNull()?.descending ?: false
        val implicit = inequalityFields.distinct()
            .filter { it != "__name__" && it !in ordered }
            .sortedWith { a, b -> compareFieldPaths(a, b) }

        val result = explicit.toMutableList()
        implicit.forEach { result.add(OrderClause(it, NfsPaths.fieldPath(it), descending)) }
        if ("__name__" !in ordered) result.add(OrderClause("__name__", FieldPath.documentId(), descending))
        return result
    }

    /**
     * Terms in a filter's disjunctive normal form (capped just past the limit)
     * and the most array-contains filters any one term holds. in and
     * array-contains-any expand into one == / array-contains per value.
     */
    private fun normalForm(wire: Map<*, *>): Pair<Int, Int> {
        val composite = wire["composite"] as? String
        if (composite != null) {
            val isOr = composite == "or"
            var terms = if (isOr) 0 else 1
            var arrayContains = 0
            for (child in (wire["filters"] as? List<*>) ?: emptyList<Any?>()) {
                val (childTerms, childArrayContains) = normalForm(child as? Map<*, *> ?: continue)
                terms = minOf(if (isOr) terms + childTerms else terms * childTerms, MAX_DISJUNCTIONS + 1)
                // An AND term takes one term from every child; an OR term is one child's term.
                arrayContains = if (isOr) maxOf(arrayContains, childArrayContains) else arrayContains + childArrayContains
            }
            return terms to arrayContains
        }

        val op = wire["op"] as? String ?: ""
        val count = if (op == "in" || op == "array-contains-any") maxOf(1, (wire["value"] as? List<*>)?.size ?: 1) else 1
        return minOf(count, MAX_DISJUNCTIONS + 1) to (if (op == "array-contains" || op == "array-contains-any") 1 else 0)
    }

    @Suppress("UNCHECKED_CAST")
    private fun buildFilter(wire: Map<String, Any?>, db: FirebaseFirestore, collectionPath: String?, stats: Stats): Filter {
        val composite = wire["composite"] as? String
        if (composite != null) {
            val children = ((wire["filters"] as? List<Map<String, Any?>>) ?: emptyList()).map { buildFilter(it, db, collectionPath, stats) }
            if (children.isEmpty()) throw NfsErrors.invalid("An $composite filter needs at least one filter.")
            if (composite == "or") {
                stats.hasOr = true
                return Filter.or(*children.toTypedArray())
            }
            return Filter.and(*children.toTypedArray())
        }

        val field = wire["field"] as? String ?: ""
        val op = wire["op"] as? String ?: ""
        val path = NfsPaths.fieldPath(field)
        val raw = wire["value"]

        fun operand(value: Any?): Any? =
            if (field == "__name__") documentIdOperand(value, db, collectionPath) else NfsCodec.decode(value, db, NfsContext.QUERY)

        fun list(): List<Any?> {
            val values = raw as? List<*>
            if (values.isNullOrEmpty()) throw NfsErrors.invalid("The $op operator needs a non-empty list.")
            val limit = if (op == "not-in") MAX_NOT_IN_VALUES else MAX_DISJUNCTIONS
            if (values.size > limit) throw NfsErrors.invalid("The $op operator supports at most $limit values.")
            return values.map { operand(it) }
        }

        // Mirrors the iOS checks, where the SDK raises uncatchable exceptions.
        if (field == "__name__" && (op == "array-contains" || op == "array-contains-any")) {
            throw NfsErrors.invalid("The $op operator cannot be used on the document id.")
        }
        if (op == "array-contains-any" && (raw as? List<*>)?.any { it is List<*> } == true) {
            throw NfsErrors.invalid("array-contains-any values cannot be arrays (Firestore does not support nested arrays).")
        }
        if (op in listOf("!=", "<", "<=", ">", ">=", "not-in")) stats.inequalityFields.add(field)

        val isListOperator = op in listOf("in", "not-in", "array-contains-any")
        val value = if (isListOperator) null else operand(raw)
        // array-contains null/NaN is valid Firestore (matches arrays holding null/NaN);
        // only range comparisons are meaningless for them.
        if (!isListOperator && (value == null || (value is Double && value.isNaN())) && op !in listOf("==", "!=", "array-contains")) {
            throw NfsErrors.invalid("null and NaN only support ==, != and array-contains comparisons.")
        }

        return when (op) {
            "==" -> Filter.equalTo(path, value)
            "!=" -> { stats.notEqual++; Filter.notEqualTo(path, value) }
            "<" -> Filter.lessThan(path, value)
            "<=" -> Filter.lessThanOrEqualTo(path, value)
            ">" -> Filter.greaterThan(path, value)
            ">=" -> Filter.greaterThanOrEqualTo(path, value)
            "array-contains" -> Filter.arrayContains(path, value)
            "array-contains-any" -> { stats.disjunctive++; Filter.arrayContainsAny(path, list()) }
            "in" -> { stats.disjunctive++; Filter.inArray(path, list()) }
            "not-in" -> { stats.notIn++; Filter.notInArray(path, list()) }
            else -> throw NfsErrors.invalid("Unsupported operator [$op].")
        }
    }

    private fun documentIdOperand(value: Any?, db: FirebaseFirestore, collectionPath: String?): Any {
        if (value is Map<*, *> && value["__type"] == "reference") {
            return NfsPaths.document(value["path"] as? String ?: "", db)
        }
        val id = value as? String
        if (id.isNullOrEmpty()) throw NfsErrors.invalid("Document id filters need a string id or a reference.")
        if (collectionPath != null) {
            if (id.contains("/")) throw NfsErrors.invalid("Use a plain document id (no slashes) when filtering a collection by document id.")
            return NfsPaths.document("$collectionPath/$id", db)
        }
        return NfsPaths.document(id, db)
    }

    private fun documentIdCursorValue(value: Any?, collectionPath: String?): String {
        var id = value as? String
        if (value is Map<*, *> && value["__type"] == "reference") {
            val path = value["path"] as? String ?: ""
            id = if (collectionPath == null) path else path.substringAfterLast('/')
        }
        if (id.isNullOrEmpty()) throw NfsErrors.invalid("Document id cursor values must be strings.")
        if (collectionPath != null && id.contains("/")) {
            throw NfsErrors.invalid("Use a plain document id as a cursor value for a collection query.")
        }
        if (collectionPath == null && NfsPaths.segments(id).size % 2 != 0) {
            throw NfsErrors.invalid("Collection group cursors on document id need a full document path.")
        }
        return id
    }

    private fun compareFieldPaths(a: String, b: String): Int {
        val left = a.split('.')
        val right = b.split('.')
        for (index in 0 until minOf(left.size, right.size)) {
            val result = left[index].compareTo(right[index])
            if (result != 0) return result
        }
        return left.size.compareTo(right.size)
    }

    /**
     * A value cursor lines up with the explicit orderBy clauses. A snapshot
     * cursor ({document, values}) carries the snapshot's own value for every
     * normalized order field, ending with its document: building the bound
     * from those (instead of re-reading the document) keeps the cursor where
     * the snapshot was even if the document has changed or been deleted.
     */
    private fun cursorBound(cursor: Map<String, Any?>, explicit: List<OrderClause>, normalized: List<OrderClause>, db: FirebaseFirestore, collectionPath: String?): CursorBound {
        val inclusive = cursor["inclusive"] as? Boolean ?: true
        val raw = cursor["values"] as? List<*> ?: throw NfsErrors.invalid(
            if (cursor["document"] != null) "A document cursor needs the snapshot's value for each ordered field (the PHP API sends these)."
            else "Cursor values must be a list.",
        )

        if (cursor["document"] != null) {
            val reference = NfsPaths.document(cursor["document"] as? String ?: throw NfsErrors.invalid("Cursor document must be a path."), db)
            if (raw.size != normalized.size) {
                throw NfsErrors.invalid("The cursor for document [${reference.path}] needs one value per ordered field (${normalized.joinToString(", ") { it.field }}).")
            }
            val values = normalized.mapIndexed { index, order ->
                if (order.field == "__name__") {
                    val slot = raw[index] as? Map<*, *>
                    val slotPath = (slot?.get("path") as? String)?.let { runCatching { NfsPaths.segments(it).joinToString("/") }.getOrNull() }
                    if (slot?.get("__type") != "reference" || slotPath != reference.path) {
                        throw NfsErrors.invalid("The cursor for document [${reference.path}] must end in a reference to it.")
                    }
                    if (collectionPath != null && reference.parent.path != collectionPath) {
                        throw NfsErrors.invalid("The cursor document [${reference.path}] is not in [$collectionPath].")
                    }
                    documentIdCursorValue(slot, collectionPath)
                } else {
                    NfsCodec.decode(raw[index], db, NfsContext.QUERY)
                }
            }
            return CursorBound(values.toTypedArray(), inclusive, isSnapshot = true)
        }

        if (raw.isEmpty() || raw.size > explicit.size) {
            throw NfsErrors.invalid("Cursor values need a matching orderBy() clause for each value.")
        }

        val values = raw.mapIndexed { index, value ->
            if (explicit[index].field == "__name__") documentIdCursorValue(value, collectionPath) else NfsCodec.decode(value, db, NfsContext.QUERY)
        }
        return CursorBound(values.toTypedArray(), inclusive, isSnapshot = false)
    }
}

// MARK: - Writes

internal sealed class NfsWrite(val ref: DocumentReference) {
    class Set(ref: DocumentReference, val data: Map<String, Any?>, val merge: Boolean, val mergeFields: List<String>?) : NfsWrite(ref)
    class Update(ref: DocumentReference, val data: Map<String, Any?>) : NfsWrite(ref)
    class Delete(ref: DocumentReference) : NfsWrite(ref)

    fun applyTo(batch: WriteBatch) {
        when (this) {
            is Set -> when {
                mergeFields != null -> batch.set(ref, data, SetOptions.mergeFields(mergeFields))
                merge -> batch.set(ref, data, SetOptions.merge())
                else -> batch.set(ref, data)
            }
            is Update -> batch.update(ref, data)
            is Delete -> batch.delete(ref)
        }
    }

    fun applyTo(transaction: Transaction) {
        when (this) {
            is Set -> when {
                mergeFields != null -> transaction.set(ref, data, SetOptions.mergeFields(mergeFields))
                merge -> transaction.set(ref, data, SetOptions.merge())
                else -> transaction.set(ref, data)
            }
            is Update -> transaction.update(ref, data)
            is Delete -> transaction.delete(ref)
        }
    }

    companion object {
        @Suppress("UNCHECKED_CAST")
        fun decodeAll(raw: Any?, db: FirebaseFirestore): List<NfsWrite> {
            val list = raw as? List<Map<String, Any?>> ?: throw NfsErrors.invalid("Writes must be a list.")
            if (list.size > 500) throw NfsErrors.invalid("At most 500 writes can be committed together.")
            return list.map { decode(it, db) }
        }

        @Suppress("UNCHECKED_CAST")
        private fun decode(wire: Map<String, Any?>, db: FirebaseFirestore): NfsWrite {
            val ref = NfsPaths.document(wire["path"] as? String ?: "", db)

            return when (wire["type"]) {
                "set" -> {
                    val mergeFields = (wire["mergeFields"] as? List<*>)?.map { it.toString() }
                    val merge = wire["merge"] as? Boolean ?: false
                    val data = NfsCodec.decodeMap(wire["data"], db, if (merge || mergeFields != null) NfsContext.MERGE else NfsContext.SET)
                    mergeFields?.let { fields ->
                        if (fields.isEmpty()) throw NfsErrors.invalid("mergeFields cannot be empty.")
                        for (field in fields) {
                            if (!contains(data, NfsPaths.fieldSegments(field))) {
                                throw NfsErrors.invalid("Merge field [$field] is not in the data.")
                            }
                        }
                    }
                    Set(ref, data, merge, mergeFields)
                }
                "update" -> {
                    val raw = wire["data"] as? Map<String, Any?>
                    if (raw.isNullOrEmpty()) throw NfsErrors.invalid("update() needs at least one field.")
                    val data = LinkedHashMap<String, Any?>()
                    for ((field, value) in raw) {
                        NfsPaths.fieldSegments(field).forEach { NfsPaths.guardWritableName(it) }
                        data[field] = NfsCodec.decode(value, db, NfsContext.UPDATE, depth = 1)
                    }
                    Update(ref, data)
                }
                "delete" -> Delete(ref)
                else -> throw NfsErrors.invalid("Unknown write type.")
            }
        }

        private fun contains(data: Map<String, Any?>, segments: List<String>): Boolean {
            var current: Any? = data
            for (segment in segments) {
                val map = current as? Map<*, *> ?: return false
                if (!map.containsKey(segment)) return false
                current = map[segment]
            }
            return true
        }
    }
}

// MARK: - Operations

internal object NfsOperations {
    fun source(value: Any?): Source = when (value) {
        "server" -> Source.SERVER
        "cache" -> Source.CACHE
        else -> Source.DEFAULT
    }

    fun commit(writes: List<NfsWrite>, waitForServer: Boolean, db: FirebaseFirestore): Map<String, Any?> {
        val batch = db.batch()
        writes.forEach { it.applyTo(batch) }
        val task = batch.commit()

        if (!waitForServer) {
            // The batch is already in the local cache; the task completes when
            // the backend answers. Late failures surface as WriteFailed events.
            val paths = writes.map { it.ref.path }
            task.addOnFailureListener(NfsRuntime.callbackExecutor) { error ->
                val failure = NfsErrors.from(error)
                NfsBridge.dispatch(
                    "VitisStudio\\NativeFirestore\\Events\\WriteFailed",
                    mapOf("paths" to paths, "code" to failure.code, "message" to failure.message),
                )
            }
            return mapOf("committed" to writes.size, "acknowledged" to false)
        }

        NfsRuntime.await(task, "Committing ${writes.size} write(s)")
        return mapOf("committed" to writes.size, "acknowledged" to true)
    }
}

// MARK: - Listeners

internal object NfsListeners {
    private val lock = ReentrantLock()
    private val registrations = HashMap<String, ListenerRegistration>()

    @Suppress("UNCHECKED_CAST")
    fun add(target: Map<String, Any?>, includeMetadataChanges: Boolean, db: FirebaseFirestore): String {
        val id = UUID.randomUUID().toString()
        val changes = if (includeMetadataChanges) MetadataChanges.INCLUDE else MetadataChanges.EXCLUDE

        val registration = if (target["type"] == "document") {
            val ref = NfsPaths.document(target["path"] as? String ?: "", db)
            ref.addSnapshotListener(NfsRuntime.callbackExecutor, changes) { snapshot, error ->
                if (snapshot != null) {
                    val document = NfsCodec.document(snapshot)
                    val metadata = document["metadata"] as Map<String, Any?>
                    NfsBridge.dispatch(
                        "VitisStudio\\NativeFirestore\\Events\\DocumentChanged",
                        mapOf(
                            "listenerId" to id,
                            "path" to document["path"],
                            "id" to document["id"],
                            "exists" to document["exists"],
                            "data" to document["data"],
                            "fromCache" to metadata["fromCache"],
                            "hasPendingWrites" to metadata["hasPendingWrites"],
                            "pendingServerTimestamps" to metadata["pendingServerTimestamps"],
                        ),
                    )
                } else {
                    fail(id, error)
                }
            }
        } else {
            val wire = target["query"] as? Map<String, Any?> ?: emptyMap()
            val query = NfsQueryBuilder.build(wire, db)
            val path = wire["path"] as? String ?: ""
            query.addSnapshotListener(NfsRuntime.callbackExecutor, changes) { snapshot, error ->
                if (snapshot != null) {
                    val encoded = NfsCodec.query(snapshot)
                    val metadata = encoded["metadata"] as Map<String, Any?>
                    NfsBridge.dispatch(
                        "VitisStudio\\NativeFirestore\\Events\\QuerySnapshotChanged",
                        mapOf(
                            "listenerId" to id,
                            "path" to path,
                            "documents" to encoded["documents"],
                            "changes" to encoded["changes"],
                            "fromCache" to metadata["fromCache"],
                            "hasPendingWrites" to metadata["hasPendingWrites"],
                        ),
                    )
                } else {
                    fail(id, error)
                }
            }
        }

        lock.withLock { registrations[id] = registration }
        return id
    }

    fun remove(id: String) {
        lock.withLock { registrations.remove(id) }?.remove()
    }

    fun removeAll(): Int {
        val all = lock.withLock {
            val copy = registrations.values.toList()
            registrations.clear()
            copy
        }
        all.forEach { it.remove() }
        return all.size
    }

    private fun fail(id: String, error: Throwable?) {
        remove(id)
        val failure = NfsErrors.from(error)
        NfsBridge.dispatch(
            "VitisStudio\\NativeFirestore\\Events\\ListenerFailed",
            mapOf("listenerId" to id, "code" to failure.code, "message" to failure.message),
        )
    }
}

// MARK: - Transactions

/**
 * A Firestore transaction remote-controlled from PHP. The SDK runs the
 * transaction function on a background thread, possibly several times
 * (contention retries); each run parks here and executes PHP's commands —
 * reads, then one commit or abort. When the SDK retries, PHP's pending commit
 * returns "retry" and PHP re-runs its callback.
 */
internal class NfsTransactionSession {
    sealed class Command {
        class Get(val ref: DocumentReference, val reply: java.util.concurrent.CompletableFuture<Map<String, Any?>>) : Command()
        class Commit(val writes: List<NfsWrite>) : Command()
        object Abort : Command()
    }

    val id: String = UUID.randomUUID().toString()
    private val lock = ReentrantLock()
    private val changed = lock.newCondition()
    private val commands = ArrayDeque<Command>()
    var attempt = 0
        private set
    private var finished = false
    private var failure: Throwable? = null

    fun run(db: FirebaseFirestore, maxAttempts: Int, commandTimeoutMillis: Long) {
        val options = TransactionOptions.Builder().setMaxAttempts(maxOf(1, maxAttempts)).build()

        db.runTransaction(options, Transaction.Function<Any?> { transaction ->
            lock.withLock {
                attempt++
                commands.clear()
                changed.signalAll()
            }

            while (true) {
                when (val command = next(commandTimeoutMillis)) {
                    null -> throw NfsException("deadline-exceeded", "The app did not finish the transaction in time.")
                    is Command.Get -> try {
                        command.reply.complete(NfsCodec.document(transaction.get(command.ref)))
                    } catch (error: Exception) {
                        command.reply.completeExceptionally(error)
                    }
                    is Command.Commit -> {
                        command.writes.forEach { it.applyTo(transaction) }
                        return@Function null
                    }
                    is Command.Abort -> throw NfsException("aborted", "Transaction aborted by the app.")
                }
            }
            @Suppress("UNREACHABLE_CODE")
            null
        }).addOnCompleteListener(NfsRuntime.callbackExecutor) { task ->
            lock.withLock {
                finished = true
                failure = if (task.isSuccessful) null else (task.exception ?: NfsException("unknown", "Transaction failed."))
                changed.signalAll()
            }
        }
    }

    fun waitUntilStarted(timeoutMillis: Long) {
        lock.withLock {
            val deadline = System.currentTimeMillis() + timeoutMillis
            while (attempt == 0 && !finished) {
                val remaining = deadline - System.currentTimeMillis()
                if (remaining <= 0) break
                changed.await(remaining, TimeUnit.MILLISECONDS)
            }
            failure?.let { throw NfsErrors.from(it) }
            if (attempt == 0) throw NfsException("deadline-exceeded", "The transaction did not start.")
        }
    }

    fun get(ref: DocumentReference, timeoutMillis: Long): Map<String, Any?> {
        val reply = java.util.concurrent.CompletableFuture<Map<String, Any?>>()
        submit(Command.Get(ref, reply))
        try {
            return reply.get(timeoutMillis, TimeUnit.MILLISECONDS)
        } catch (error: TimeoutException) {
            throw NfsException("deadline-exceeded", "Transaction read of ${ref.path} timed out.")
        } catch (error: ExecutionException) {
            throw NfsErrors.from(error.cause)
        }
    }

    /** True when committed, false when Firestore started another attempt. */
    fun commit(writes: List<NfsWrite>, timeoutMillis: Long): Boolean {
        val attemptAtCommit = lock.withLock { attempt }
        submit(Command.Commit(writes))

        lock.withLock {
            val deadline = System.currentTimeMillis() + timeoutMillis
            while (!finished && attempt == attemptAtCommit) {
                val remaining = deadline - System.currentTimeMillis()
                if (remaining <= 0) throw NfsException("deadline-exceeded", "The transaction commit did not complete in time.")
                changed.await(remaining, TimeUnit.MILLISECONDS)
            }
            if (finished) {
                failure?.let { throw NfsErrors.from(it) }
                return true
            }
            return false
        }
    }

    fun abort(timeoutMillis: Long) {
        try {
            submit(Command.Abort)
        } catch (ignored: Exception) {
            return
        }
        lock.withLock {
            val deadline = System.currentTimeMillis() + timeoutMillis
            while (!finished) {
                val remaining = deadline - System.currentTimeMillis()
                if (remaining <= 0) break
                changed.await(remaining, TimeUnit.MILLISECONDS)
            }
        }
    }

    private fun submit(command: Command) {
        lock.withLock {
            failure?.let { throw NfsErrors.from(it) }
            if (finished) throw NfsException("failed-precondition", "The transaction has already finished.")
            commands.addLast(command)
            changed.signalAll()
        }
    }

    private fun next(timeoutMillis: Long): Command? {
        lock.withLock {
            val deadline = System.currentTimeMillis() + timeoutMillis
            while (commands.isEmpty()) {
                val remaining = deadline - System.currentTimeMillis()
                if (remaining <= 0) return null
                changed.await(remaining, TimeUnit.MILLISECONDS)
            }
            return commands.removeFirst()
        }
    }
}

internal object NfsTransactions {
    private val lock = ReentrantLock()
    private val sessions = HashMap<String, NfsTransactionSession>()

    fun begin(db: FirebaseFirestore, maxAttempts: Int): String {
        val session = NfsTransactionSession()
        val timeout = NfsRuntime.timeoutMillis
        lock.withLock { sessions[session.id] = session }

        session.run(db, maxAttempts, maxOf(timeout * 2, 30_000L))

        try {
            session.waitUntilStarted(timeout)
        } catch (error: Exception) {
            end(session.id)
            throw error
        }

        return session.id
    }

    fun session(id: String): NfsTransactionSession = lock.withLock {
        sessions[id] ?: throw NfsException("failed-precondition", "Unknown or finished transaction [$id].")
    }

    fun find(id: String): NfsTransactionSession? = lock.withLock { sessions[id] }

    fun end(id: String) {
        lock.withLock { sessions.remove(id) }
    }
}

// MARK: - Auth helpers

internal object NfsAuthSupport {
    /** Empty credentials make the Auth SDK throw; report them like iOS does. */
    fun required(payload: Map<String, Any?>, key: String): String =
        (payload[key] as? String)?.takeIf { it.isNotEmpty() } ?: throw NfsErrors.invalid("FirebaseAuth needs a non-empty $key.")

    private var listener: FirebaseAuth.AuthStateListener? = null
    private val lock = ReentrantLock()

    fun encode(user: FirebaseUser?): Map<String, Any?>? {
        if (user == null) return null
        return mapOf(
            "uid" to user.uid,
            "email" to user.email,
            "displayName" to user.displayName,
            "photoUrl" to user.photoUrl?.toString(),
            "phoneNumber" to user.phoneNumber,
            "emailVerified" to user.isEmailVerified,
            "isAnonymous" to user.isAnonymous,
            "providerId" to user.providerId,
            "createdAt" to user.metadata?.creationTimestamp,
            "lastSignInAt" to user.metadata?.lastSignInTimestamp,
        )
    }

    fun startListening(auth: FirebaseAuth) = lock.withLock {
        if (listener != null) return@withLock
        val newListener = FirebaseAuth.AuthStateListener { current ->
            NfsBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\AuthStateChanged", mapOf("user" to encode(current.currentUser)))
        }
        listener = newListener
        auth.addAuthStateListener(newListener)
    }

    fun stopListening(auth: FirebaseAuth) = lock.withLock {
        listener?.let { auth.removeAuthStateListener(it) }
        listener = null
    }
}
