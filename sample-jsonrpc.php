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
<!DOCTYPE HTML>
<html>
<head>
<script>

// allow overrides
this.jsonrpc = {
	rpc: {}
};

//////////////////
// Private context
(function(){

// Declare Public methods
(function(){
    this.jsonrpc.rpc['service'] = jsonrpc_service;
    this.jsonrpc.rpc['get'] = jsonrpc_get;
})();


/**
    Given an URI and name, generate functions from an SMD response.
*/
function jsonrpc_service( url, name, args, callback ) {
    var _rpc = new Factory();
    _rpc.createCallback();
    _rpc.addCallback( function(){ _rpc.processSMD(); });
    _rpc.parseArgs( url, name, args, callback );
    _rpc.parseService();
    _rpc.call();
    return _rpc._obj;
}

/**
    Given an URI, JSON-RPC method name, and arguments,
    Return a callback object that will receive the response data.
*/
function jsonrpc_get( url, name, args, callback ) {
    var _rpc = new Factory();
    _rpc.createCallback();
    _rpc.addCallback( function(){ _rpc.processBulk(); });
    _rpc.parseBulkArgs( url, name, args, callback );
    _rpc.call();
    return _rpc._obj;
}


// Static Variables
var _jsonrpc_call_current_id = 0;

// internal factory class
function Factory() {
    // initialize instance variables
    this._callbacks = new Array();
}

Factory.prototype = {
    createCallback: function() {
        var self = this;
        function Callback( callback ) {
            return self.addCallback.call( self, callback );
        }
        this.isCallback = function( arg ) {
            return typeof arg == 'function' && !( arg instanceof Callback );
        };
        
        var _obj = this._obj = Callback;
        _obj.valueOf = _obj.toString = this.throwInProgress;
        return _obj;
    },
    
    pushData: function( name, args ) {
        this._data.push({
            method: name,
            params: args,
            id: ++_jsonrpc_call_current_id,
        });
    },
    
    createBulkCallback: function( name ) {
    	var self_obj = this._obj;
        var _rpc = new Factory();
        function Callback( callback ) {
            _rpc.addCallback.call( _rpc, callback );
            return self_obj;
        }
        _rpc.isCallback = function( arg ) {
            return typeof arg == 'function' && !( arg instanceof Callback );
        };

        var _obj = _rpc._obj = Callback;
        _obj.valueOf = _obj.toString = _rpc.throwInProgress;
        this._obj[ name ] = _obj;
        return _rpc;
    },
    
    throwInProgress: function() {
        throw 'Asynchronous call in progress';
    },
    
    parseBulkArgs: function( arg0, name, args, callback ) {
        if( callback == undefined && args == undefined
            && name == undefined && typeof arg0 == 'object' )
        {
            this._deferred = 0;
            this._data = new Array();
            this._result_names = new Array();
            
            // process url first
            this._url = arg0.url;

            for( var i in arg0 ) {
                if( i == 'url' ) ;
                else if( i == 'callback' ) this.addCallback( arg0.callback );
                else {
                    var _callback = this.createBulkCallback( i );
                    var argi = arg0[i];
                    
                    // use the method name as the return name
                    if( Array.isArray( argi )) {
                        this.pushData( i, argi );
                    }
                    // or object with one key is method name
                    else {
                        var onekey = false;
                        for( var j in argi ) {
                            if(onekey) throw 'Method object must have a single key';
                            this.pushData( j, argi[j] );
                            onekey = true;
                        }
                    }
                    
                    this._result_names.push({
                        id: _jsonrpc_call_current_id,
                        callback: _callback
                        });
                }
            }
        }
        else
            this.parseArgs( arg0, name, args, callback );
    },
    
    parseArgs: function( url, name, args, callback ) {
        // search args for callback
        if( callback == undefined && args == undefined
            && name == undefined && this.isCallback( url )) {
                callback = url;
                url = undefined;
        }
        else if( callback == undefined && args == undefined
            && this.isCallback( name )) {
                callback = name;
                name = undefined;
        }
        else if( callback == undefined && this.isCallback( args )) {
            callback = args;
            args = undefined;
        }
        
        // shift args right
        if( args == undefined ) {
            args = name;
            name = url;
            url = undefined;
        }
        
        // default args
        if( callback ) this.addCallback( callback );
        if( url == undefined ) url = '';
        if( name == undefined ) name = '';
        if( args == undefined ) args = new Array();
        else args = this.popCallback( args );
        
        this.parseDeferred( args );
            
        // prepare jsonrpc data
        var data = {};
        data.id = ++_jsonrpc_call_current_id;
        data.method = name;
        data.params = args;

        this._data = data;
        this._url = url;
    },
    
    parseDeferred: function( args ) {
        // check args for Callback type functions.
        // reverse callback so args evaluate first
        // then we evaluate.
        var self = this;
        this._deferred = 0;
        for( var i in args ) {
            var arg = args[i];
            if( typeof arg == 'function' && ! this.isCallback( arg )) {
                arg.call( arg, function() {
                    args[i] = this.valueOf();
                    --self._deferred;
                    if( self._deferred == 0 ) self.call();
                });
                ++this._deferred;
            }
        }
    },
    
    parseService: function() {
    	var name = this._data.method;
    	if ( name == '' || name == undefined ) name = 'rpc.service';
    	else {
        	var _obj = this._obj;
        	var _url = this._url;
        	var _response = name;
    		if( Array.isArray( _response )) {
        		for ( var key in _response ) {
                    var _key = _response[key];
        	    	_obj[_key] = this.newFunction( _url, _key );
        		}
        		name = undefined;
    			this._deferred = -1;
    		} else if( typeof _response == 'object' && 'services' in _response ) {
    			if( 'target' in _response ) {
    				_url = _response.target;
    			}
        		for ( var key in _response.services ) {
        	    	_obj[key] = this.newFunction( _url, key );
        		}
        		name = undefined;
    			this._deferred = -1;
        	}
    	}
    	this._data.method = name;
    },
        
    call: function() {
    	if( this._deferred ) return;
        var data = JSON.stringify( this._data );
        var xhr = new XMLHttpRequest();
        xhr.onreadystatechange = this.jsonrpc_handle;
        xhr.self = this;
        xhr.open( 'POST', this._url );
        xhr.setRequestHeader( 'Content-Type', 'application/json' );
        xhr.send( data );
    },

    addCallback: function( callback ) {
        if( callback == undefined || callback == null ) throw 'Callback argument is empty';
        if( ! this.isCallback( callback )) throw 'Callback argument not a valid function';
        if( this._response == undefined ) this._callbacks.push( callback );
        else callback.call( this._obj, this._obj );
        return this._obj;
    },
    
    popCallback: function( args ) {
        // convert to Array type
        args = Array.prototype.slice.call( args );
        if( args.length > 0 && this.isCallback( args[args.length-1] )) {
            var callback = args.pop();
            this.addCallback( callback );
        }
        return args;
    },
    
    processCallbacks: function() {
        var obj = this._obj
        var callbacks = this._callbacks;

        // call all callbacks
        for( var cb in callbacks ) {
        	try {
        	    callbacks[cb].call( obj, obj );
        	} catch( err ) {
                console.log(err);
        	}
        }

        // remove callbacks
        callbacks.length = 0;
    },

    jsonrpc_handle: function() {
        if( this.readyState == this.DONE ) {
            var self = this.self;
            if( this.status == 200 ) {
                if( this.getResponseHeader('Content-Type') != 'application/json' ) {
                	var _obj = self._obj;
                    _obj.valueOf = _obj.toString = function() {
                        throw 'Invalid response content type';
                    };
                }
                self._response = JSON.parse( this.responseText );
            } else {
                var _obj = self._obj;
                var err = this.statusText;
        		_obj.valueOf = _obj.toString = function() {
                	throw err;
        		};
            }
            // call all callbacks
            self.processCallbacks();
        }
    },
    
    throwInvalidResponse: function() {
        throw 'Invalid response (not JSON content type)';
    },
    
    processBulk: function() {
        if( this._result_names ) {
            // bulk request
            var callbacks = new Array();
            var names = this._result_names;
            var _obj = this._obj;
            var _response = this._response;
            for( var i=0; i < _response.length; ++i ) {
                var id = _response[i].id;
                for( var j=0; j < names.length; ++j ) {
                    if( id == names[j].id ) {
                        var rpc = names[j].callback;
                        var obj = rpc._obj;
                        var response = this.processResponse( obj, _response[i] );
                        if(response) this.copyAttrs( obj, response );
                        callbacks.push( rpc );
                        break;
                    }
                }
            }
            for( var rpc in callbacks ) {
                callbacks[rpc].processCallbacks();
            }
        }
        else {
            var response = this.processResponse( this._obj, this._response );
            if(response) this.copyAttrs( this._obj, response );
        }
    },

    copyAttrs: function( _obj, _response ) {
        if( typeof _response == 'string' ) {
            // add overrides
            _obj.valueOf = _obj.toString = function(){ return _response; }
        }
        else if( typeof _response == 'number' || typeof _response == 'boolean' ) {
            // add overrides
            _obj.valueOf = function(){ return _response; }
            _obj.toString = function(){ return _response.toString(); }
        }
        else if( typeof _response == 'object' ) {
            if ( Array.isArray( _response )) {
                // copy array members
                for( var i = 0; i < _response.length; i++ ) {
                    _obj[i] = _response[i];
                }
            } else {
                // copy object
                for( var attr in _response ) {
                    if( _response.hasOwnProperty( attr )) _obj[attr] = _response[attr];
                }
            }
            // add overrides
            _obj.valueOf = function(){ return _response; }
            _obj.toString = function(){ return _response.toString(); }
        }
        else {
            _obj.valueOf = _obj.toString = function() {
        		throw 'Invalid result type ' + typeof _response;
        	};
        }
    },
    
    processResponse: function( _obj, _response ) {
        // check for errors
        if( typeof _response != 'object' ) {
            _obj.valueOf = _obj.toString = function() {
        		throw 'Invalid response (not a Javascript object)';
        	};
        	return null;
        }
        if( 'error' in _response ) {
            _obj.valueOf = _obj.toString = function() {
        		throw _response.error;
        	};
        	return null;
        }
        if( ! 'result' in _response ) {
            _obj.valueOf = _obj.toString = function() {
        		throw 'Invalid response (not a JSON-RPC object)';
        	};
        	return null;
        }

        return _response.result;
    },

    processSMD: function() {
        var _obj = this._obj;
        var _response = this.processResponse( _obj, this._response );
        if( !_response ) return;
        if( ! 'services' in _response ) {
            _obj.valueOf = _obj.toString = function() {
        		throw 'Invalid SMD response object';
        	};
        	return;
        }

        var _url = this._url;
        _response = _response.services;
        for ( var key in _response ) {
            // TODO handle class methods
            _obj[key] = this.newFunction( _url, key );
        }
    },

    newFunction: function( url, name ) {
        var _obj = new Function( 'return jsonrpc.rpc.get( "' + url + '", "' + name + '", arguments );' );
        return _obj;
    }
};

})();
// END private context
//////////////////////
</script>

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

<script>

//////////////////
// Test

// simple add
jsonrpc.rpc.service( /* '', '', undefined *IMPLIED* */ function() {
    // this is the resulting service object with remote interfaces
    this.add( 1.1, 2.2, function() {
        // this is the result of the remote add operation
        console.log(this);
    });
});

// better to call import smd once and save the remote interfaces
var my_smd = jsonrpc.rpc.service();
my_smd( function() {
    // remote method invocations can also be saved for later callbacks
    var concat_result = my_smd.concat( 'arg 0', 'arg 1', function() {
        // or used inside of callbacks explicitly
        console.log(concat_result);
    });
});

// explicit service setup no server discovery
jsonrpc.rpc.service( /* implied url '', */ ['add', 'concat'] )
.add( 0.1, 0.2, function() {
    // 0.3
	console.log(this);
});

// batch call
jsonrpc.rpc.get({
    // url of server
    url: '',
    // result and method name are both concat
    concat: [ 'arg 2', 'arg 3' ],
    // result is variable add1 of the method add
    add1: { add: [ 3.3, 4.4 ] },
    // callback when all results are available
    callback: function() {
        // 7.7
        console.log(this.add1);
        // arg 2 arg 3
        console.log(this.concat);
    }
});

// batch with per-method callbacks
jsonrpc.rpc.get({
    str0: { concat: [ 'arg 4', 'arg 5' ] },
    add: [ 6, 7 ]

// get batch returns callback functions for each result variable
}).add( function() {
    // 13
    console.log(this);

// batch callback functions can be daisy-chained
}).str0( function() {
    // arg 4 arg 5
    console.log(this);
});

/*
// batch service callback with cascaded calls
jsonrpc.rpc.service( function() {
    this.add( this.add( 10.1, 2.1 ), 3.2, function() {
        // 15.4
        console.log(this);
    });
    this.concat( 'arg 6', this.concat( 'arg 7', 'arg 8' ), function() {
        // arg 6 arg 7 arg 8
        console.log(this);
    });
});
*/

/*
// batch cascaded get
jsonrpc.rpc.get({
    add0: { add: [ null, 2.3 ], 0: { add1: null }},
    add1: { add: [ 5.4, null ], 1: { add: [ 7.6, 8.7 ] }}
}).add0( function() {
    // 24.0
    console.log(this);
}).add1( function() {
    // 21.7
    console.log(this);
});
*/

</script>
</head>
<body>
<a href="javascript:test()">Test</a>
</body>
</html>
