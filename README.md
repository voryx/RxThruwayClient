# RxPHP WAMP Client


Experimental WAMP client

If the Registration Handler throws an exception, `thruway.error.invocation_exception`
is returned to the caller.

If you would like to allow more specific error messages, you must throw
a `WampErrorException` or, if using observable sequences
that are returned from the RPC, you can `onError` a 
`WampErrorException`.

