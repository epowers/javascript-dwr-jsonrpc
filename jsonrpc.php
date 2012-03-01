<?php

/*
my apologies for the quality of this code.
writing it on an ipad while riding a busy bus.
*/
function get_remote_functions()
{
    // only allow user-defined methods
    $methods = get_defined_functions();
    $methods = $methods['user'];
    $results = array();
    
    foreach( $methods as $method ) {
        // only allow methods with @RemoteMethod
        $func = new ReflectionFunction($method);
        $comment = $func->getDocComment();
        if( isset($comment) && strpos( $comment, '@RemoteMethod' ) != false ) {
            array_push( $results, $method );
        }
    }
    
    $classes = get_declared_classes();
    foreach( $classes as $class_name ) {
    	$class = new ReflectionClass( $class_name );
    	if( ! $class->isUserDefined()) continue;
        $comment = $class->getDocComment();
        if( ! isset($comment) || strpos( $comment, '@RemoteClass' ) == false ) continue;
        
        // found a remote class
        $methods = $class->getMethods();
        foreach( $methods as $method ) {
            // only allow methods with @RemoteMethod
            //$method = new ReflectionMethod($class, $method);
            $comment = $method->getDocComment();
            if( isset($comment) && strpos( $comment, '@RemoteMethod' ) != false ) {
                array_push( $results, $class_name . '.' . $method->getName() );
            }
        }
    }
    
    return $results;
}

function call_function( $method, $params )
{
    // parse method
    $method_a = explode('.', $method);
    $method = array_pop($method_a);
    $class = join('.', $method_a);
    if( $class ) {
    	$class = new ReflectionClass( $class );
    	if( ! $class->isUserDefined()) {
        	throw new Exception('Class not authorized.');
    	}
    	
        $comment = $class->getDocComment();
        if( ! isset($comment) || strpos( $comment, '@RemoteClass' ) == false ) {
        	throw new Exception('Class not authorized.');
        }
        
        $method = $class->getMethod( $method );
    } else {
        $method = new ReflectionFunction($method);
    }
    
    if( ! $method->isUserDefined()) {
        throw new Exception('Method not authorized.');
    }

    // only allow methods with @RemoteMethod
    $comment = $method->getDocComment();
    if( ! $comment || ! strpos( $comment, '@RemoteMethod' ) ) {
        throw new Exception('Method not authorized.');
    }

    // call the function
    if( $class ) {
        $result = $method->invokeArgs( null, $params );
    } else {
        $result = $method->invokeArgs( $params );
    }
    return $result;
}

function process_request( $request, $response )
{
    $status = NULL;
    try {
        $response->id = $request['id'];
        $method = $request['method'];
        $params = $request['params'];
        $response->result = call_function( $method, $params );
    } catch( Exception $e ) {
        $status = 500;
        $response->error = $e->getMessage();
    }
    return $status;
}

function process_request_array( $request, &$response )
{
    $status = NULL;
    $error_count = 0;
    $i = 0;
    try {
        $request_count = count( $request );
        for( $i=0; $i < $request_count; ++$i ) {
            $response[$i] = new stdClass();
            $status_i = process_request( $request[ $i ], $response[ $i ] );
            if( isset( $status_i )) ++$error_count;
        }
    } catch( Exception $e ) {
    	++$error_count;
    }
    
    if( $error_count > 0 ) {
        if( !isset( $request_count )) $status = 500;
        else if( $i == $request_count && $error_count < $i ) $status = 500;
        else $status = 500;
    }

    return $status;
}

function is_jsonrpc_request()
{
    return (
        $_SERVER['REQUEST_METHOD'] == 'POST' &&
        isset($_SERVER['CONTENT_TYPE']) &&
        preg_replace( '/-rpc$|-rpc;.*$|;.*$/', '', $_SERVER['CONTENT_TYPE'] ) == 'application/json'
        );
}

// Test for JSON-RPC header
if( is_jsonrpc_request() )
{
    $status = NULL;

    try {
        // convert input from json to array
        $request = json_decode( file_get_contents( 'php://input' ), true );
        if( array_key_exists( 'id', $request )) {
            $response = new stdClass();
            $status = process_request( $request, $response );
        } else {
            $response = array();
            $status = process_request_array( $request, $response );
        }
        $response = json_encode( $response );
    } catch( Exception $e ) {
        $status = 500;
        $response = new stdClass();
        $response->error = $e->getMessage();
        $response = json_encode( $response );
    }

    // send the response
    header('Content-Type: application/json', true, $status);
    echo $response;
    exit;
}

/** @RemoteClass */
class rpc
{
    /** @RemoteMethod */
    static function service()
    {
        $funcs = get_remote_functions();
        $services = new stdClass();
        foreach( $funcs as $func ) {
            $services->$func = new stdClass();
        }
        $result = new stdClass();
        $result->target = '';
        $result->transport = 'POST';
        $result->envelope = 'JSON-RPC-2.0';
        $result->SMDVersion = '2.0';
        $result->services = $services;
        return $result;
    }
}

?>
