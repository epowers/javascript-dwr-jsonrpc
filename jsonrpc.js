(function() {

//////////////////
// Private context

var dojo_xhr;
var jquery_xhr; // not implemented yet

///////
// init

function init() {

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
function jsonrpc_post( url, name, args, callback ) {
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
            return typeof arg == 'function' && arg.name != 'Callback';
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
            return typeof arg == 'function' && arg.name != 'Callback';
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
        if( url == undefined ) url = '?';
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
            try {
                args[i] = args[i].valueOf();
            } catch( e ) {
                if( typeof arg == 'function' && ! this.isCallback( arg )) {
                    arg.call( arg, function() {
                        args[i] = this.valueOf();
                        --self._deferred;
                        if( self._deferred == 0 ) self.call();
                    });
                    ++this._deferred;
                }
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
                    this.parseServiceKey( _url, _key );
        		}
        		name = undefined;
    			this._deferred = -1;
    		} else if( typeof _response == 'object' && 'services' in _response ) {
    			if( 'target' in _response ) {
    				_url = _response.target;
    			}
                for ( var key in _response.services ) {
                    this.parseServiceKey( _url, key );
                }
        		name = undefined;
    			this._deferred = -1;
        	}
    	}
    	this._data.method = name;
    },

    parseServiceKey: function( url, key ) {
        var _obj = this._obj;
        var tok = key.split('.');
        while( tok.length > 1 ) {
            var cls = tok.shift();
            _obj[cls] = {};
            _obj = _obj[cls];
        }
        _obj[key] = this.newFunction( url, key );
    },
        
    call: function() {
    	if( this._deferred ) return;
        var data = JSON.stringify( this._data );

        if( typeof dojo_xhr != 'undefined' ) {
            dojo_xhr.post({
                self: this,
                url: this._url,
                contentType: 'application/json',
                handleAs: 'json',
                postData: data,
                load: this.handle_dojo_xhr_load,
                error: this.handle_dojo_xhr_error
            });
        } else {
            var _xhr = new XMLHttpRequest();
            _xhr.onreadystatechange = this.handle_xhr;
            _xhr.self = this;
            _xhr.open( 'POST', this._url );
            _xhr.setRequestHeader( 'Content-Type', 'application/json' );
            _xhr.send( data );
        }
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

    handle_xhr: function() {
        if( this.readyState == this.DONE ) {
            var self = this.self;
            if( this.status == 200 ) {
                if( this.getResponseHeader('Content-Type') != 'application/json' ) {
                	var _obj = self._obj;
                    _obj.valueOf = _obj.toString = function() {
                        throw 'Invalid response content type';
                    };
                }
                else {
                    self._response = JSON.parse( this.responseText );
                }
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
    
    handle_dojo_xhr_load: function(response, xhr) {
        var self = this.self;
        self._response = response;
        // call all callbacks
        self.processCallbacks();
    },
    
    handle_dojo_xhr_error: function(error, xhr) {
        var self = this.self;
        var _obj = self._obj;
        _obj.valueOf = _obj.toString = function() {
            throw error;
        };
        // call all callbacks
        self.processCallbacks();
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
        if( !_response ) {
            _obj.valueOf = _obj.toString = function() {
        		throw 'Invalid response (null Javascript object)';
        	};
        	return null;
        }
        if( 'error' in _response ) {
            _obj.valueOf = _obj.toString = function() {
        		throw _response.error;
        	};
        	return null;
        }
        if( !('result' in _response) ) {
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
        var target = this._url;
        if( 'target' in _response && _response['target'] ) {
            target = _response['target'];
        }

        var services = _response.services;
        for ( var key in services ) {
            var service = services[key];
            var url = target;
            if( 'target' in service ) {
                url = service['target'];
            }
            this.parseServiceKey( url, key );
        }
    },

    newFunction: function( url, name ) {
        function jsonrpc_post_instance() {
            return jsonrpc_post( url, name, arguments );
        }
        return jsonrpc_post_instance;
    }
};

// Declare Public methods
return {
    service: jsonrpc_service,
    post: jsonrpc_post
};
}

// end init
///////////

try{
// defer until dojo.xhr loads
define(['dojo/_base/xhr'],function(xhr){
    dojo_xhr = xhr;
    return init();
});}
// TODO: test jquery ajax
catch(e){
    // or if no dojo or jquery xhr, init immediately with XMLHttpRequest
    if(typeof jsonrpc=='undefined'){ jsonrpc = {}; }
    jsonrpc.rpc = init();
}

// END private context
//////////////////////

})();
