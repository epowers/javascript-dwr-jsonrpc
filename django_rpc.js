define(["jsonrpc"], function(jsonrpc) {

//////////////////
// Private context

/**
    Given an URI, JSON-RPC method name, and arguments,
    Encode the URL with the input URI, method name, and arguments,
    Return a callback object that will receive the response data.
*/
function django_rpc_post( url, name, args, callback ) {
    var _url = url + '/' + encodeURI(name.replace(/\./g,'/'));
    for( var i = 0; i < args.length; ++i ) { 
        _url += '/' + encodeURI(args[i]);
    }
    var obj = jsonrpc.post( _url, '', [], callback );
    return obj;
}

function newFunction( url, name ) {
    function wrapper() {
        var args = Array.prototype.slice.call( arguments );
        var callback;
        if( args.length > 0 && typeof(args[args.length-1]) == 'function' ) {
            callback = args.pop();
        }
        return django_rpc_post( url, name, args, callback );
    }
    return wrapper;
}

function django_rpc_service( url, name, args, callback ) {
    if ( name == '' || name == undefined ) name = 'rpc.service';
    var _url = url + '/' + encodeURI(name.replace(/\./g,'/'));
    for( var i = 0; i < args.length; ++i ) { 
        _url += '/' + encodeURI(args[i]);
    }
    var obj = jsonrpc.service( _url, '', [], function() {
        var _obj = this;
        for( var key in _obj ) {
            var func = _obj[key];
            if( func && typeof(func) == 'function' ) {
                _obj[key] = newFunction( url, key );
            }
        }
        if( callback && typeof(callback) == 'function' ) {
            callback.call(_obj,_obj);
        }
    });
    return obj;
}

// Declare Public methods
return {
    post: django_rpc_post,
    service: django_rpc_service
    };

// END private context
//////////////////////

});
