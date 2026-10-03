/**
 * Thin JavaScript wrappers for web-view NativePHP apps (Livewire / Inertia).
 *
 * The PHP API is the primary interface; these helpers expose the same bridge
 * functions to JavaScript. Values use the plugin's wire format: Firestore
 * types are tagged objects such as {__type: 'timestamp', seconds, nanoseconds}.
 * Call configure() once before anything else (the PHP facade does this for you).
 */

async function bridgeCall(method, args = {}) {
    const response = await fetch('/_native/api/call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ method, params: { payload: JSON.stringify(args) } }),
    });

    const json = await response.json();
    const data = json && json.data !== undefined ? json.data : json;

    if (data && data.status === 'error') {
        const error = new Error(data.message || `${method} failed`);
        error.code = data.code;
        throw error;
    }

    return data && typeof data.result === 'string' ? JSON.parse(data.result) : data;
}

export const firestore = {
    configure: (config = {}) => bridgeCall('Firestore.Configure', config),
    status: () => bridgeCall('Firestore.Status'),
    getDocument: (path, source = 'default') => bridgeCall('Firestore.GetDocument', { path, source }),
    runQuery: (query, source = 'default') => bridgeCall('Firestore.RunQuery', { query, source }),
    count: (query) => bridgeCall('Firestore.Count', { query }),
    commit: (writes, waitForServer = false) => bridgeCall('Firestore.Commit', { writes, waitForServer }),
    set: (path, data, merge = false) => bridgeCall('Firestore.Commit', { writes: [{ type: 'set', path, data, merge }] }),
    update: (path, data) => bridgeCall('Firestore.Commit', { writes: [{ type: 'update', path, data }] }),
    delete: (path) => bridgeCall('Firestore.Commit', { writes: [{ type: 'delete', path }] }),
    transactionBegin: (maxAttempts = 5) => bridgeCall('Firestore.TransactionBegin', { maxAttempts }),
    transactionGet: (transactionId, path) => bridgeCall('Firestore.TransactionGet', { transactionId, path }),
    transactionCommit: (transactionId, writes) => bridgeCall('Firestore.TransactionCommit', { transactionId, writes }),
    transactionAbort: (transactionId) => bridgeCall('Firestore.TransactionAbort', { transactionId }),
    listen: (target, includeMetadataChanges = false) => bridgeCall('Firestore.Listen', { target, includeMetadataChanges }),
    stopListening: (listenerId) => bridgeCall('Firestore.StopListening', { listenerId }),
    stopAllListeners: () => bridgeCall('Firestore.StopAllListeners'),
    enableNetwork: () => bridgeCall('Firestore.EnableNetwork'),
    disableNetwork: () => bridgeCall('Firestore.DisableNetwork'),
    waitForPendingWrites: () => bridgeCall('Firestore.WaitForPendingWrites'),
};

export const firebaseAuth = {
    signInWithEmailAndPassword: (email, password) => bridgeCall('FirebaseAuth.SignInWithEmailAndPassword', { email, password }),
    createUserWithEmailAndPassword: (email, password) => bridgeCall('FirebaseAuth.CreateUserWithEmailAndPassword', { email, password }),
    signInAnonymously: () => bridgeCall('FirebaseAuth.SignInAnonymously'),
    signOut: () => bridgeCall('FirebaseAuth.SignOut'),
    currentUser: () => bridgeCall('FirebaseAuth.CurrentUser'),
    getIdToken: (forceRefresh = false) => bridgeCall('FirebaseAuth.GetIdToken', { forceRefresh }),
    sendPasswordResetEmail: (email) => bridgeCall('FirebaseAuth.SendPasswordResetEmail', { email }),
    listen: () => bridgeCall('FirebaseAuth.Listen'),
    stopListening: () => bridgeCall('FirebaseAuth.StopListening'),
};

export default { firestore, firebaseAuth };
