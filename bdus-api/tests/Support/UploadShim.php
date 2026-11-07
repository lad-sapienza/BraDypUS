<?php

// Tests only. In the CLI move_uploaded_file() always fails (the file did not arrive
// through HTTP). Controllers resolve an unqualified call in their own namespace
// first, so this stand-in lets upload-handling code be exercised end to end.
// Opt-in: it only acts while $GLOBALS['bdus_test_move_uploaded'] is true, so other
// tests keep seeing the real (failing) behaviour.
namespace Bdus\Controllers {
    function move_uploaded_file(string $from, string $to): bool
    {
        if (!empty($GLOBALS['bdus_test_move_uploaded'])) {
            return rename($from, $to);
        }
        return \move_uploaded_file($from, $to);
    }
}
