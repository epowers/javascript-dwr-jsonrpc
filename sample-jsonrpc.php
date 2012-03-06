<?php include("jsonrpc.php"); ?>
<!DOCTYPE HTML>
<html>
<head>
<script src="jsonrpc.js"></script>
<?php

/** @RemoteMethod */
function concat($arg0, $arg1)
{
    return ($arg0 . " " . $arg1);
}

/** @RemoteMethod */
function add($arg0, $arg1)
{
    return ($arg0 + $arg1);
}

?>
<script src="test-jsonrpc.js"></script>
</head>
<body>
</body>
</html>
