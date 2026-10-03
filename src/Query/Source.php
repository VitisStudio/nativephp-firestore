<?php

namespace VitisStudio\NativeFirestore\Query;

/**
 * Where a one-off read is served from. Default tries the server and falls
 * back to the offline cache.
 */
enum Source: string
{
    case Default = 'default';
    case Server = 'server';
    case Cache = 'cache';
}
