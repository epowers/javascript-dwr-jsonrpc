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
jsonrpc.rpc.post({
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
jsonrpc.rpc.post({
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

/*
// batch cascaded get
jsonrpc.rpc.post({
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
