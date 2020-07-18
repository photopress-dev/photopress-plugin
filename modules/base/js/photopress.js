window['pp'] = window['pp'] || function () {
	
	(window['pp'].q = window['pp'].q || []).push(arguments)
 
}, window['pp'].l = 1 * new Date();

/**
 * PhotoPress Framework 
 *
 */
var photopress = {
	
	items: {},
	gallery: {},
	galleries: {}, // used by masonry and sideways extensions
	options: {
		
		namespace: 'photopress'
	}
	
};

photopress.util = {
	
	
}

photopress.commandQueue = function() {

    console.log('Command Queue object created');
    var asyncCmds = [];
    var is_paused = false;
}

photopress.commandQueue.prototype = {

    push : function (cmd, callback) {

        //alert(func[0]);
        var args = Array.prototype.slice.call(cmd, 1);
        //alert(args);

        var obj_name = '';
        var method = '';
      
        var parts = cmd[0].split( '.' );
        obj_name = parts[0];
        method = parts[1];
        

        console.log('cmd queue object name %s', obj_name);
        console.log('cmd queue object method name %s', method);

        if ( method === "pause-owa" ) {

            this.pause();
        }

        // check to see if the command queue has been paused
        // used to stop tracking
        if ( ! this.is_paused ) {

            window[obj_name][method].apply(window[obj_name], args);
        }

        if ( method === "unpause-owa") {

            this.unpause();
        }

        if ( callback && ( typeof callback == 'function') ) {
            callback();
        }

    },

    loadCmds: function( cmds ) {

        this.asyncCmds = cmds;
    },

    process: function() {

        var that = this;
        var callback = function () {
            // when the handler says it's finished (i.e. runs the callback)
            // We check for more tasks in the queue and if there are any we run again
            if (that.asyncCmds.length > 0) {
                that.process();
             }
        }
        
        // give the first item in the queue & the callback to the handler
        this.push(this.asyncCmds.shift(), callback);
        
     
    },

    pause: function() {

        this.is_paused = true;
        console.log('Pausing Command Queue');
    },

    unpause: function() {

        this.is_paused = false;
        console.log('Un-pausing Command Queue');
    }
};