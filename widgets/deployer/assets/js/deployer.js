/*
 * Deployer class
 *
 * Dependences:
 * - Waterfall plugin (waterfall.js)
 */

+function ($) { "use strict";

    var Deployer = function () {

        // Init
        this.init();
    }

    Deployer.prototype.init = function() {
        this.serverId = null;
        this.activeStep = null;
        this.deploySteps = null;
        this.outputCount = 0;
        this.fileMap = {};
    }

    Deployer.prototype.execute = function(serverId, steps) {
        this.serverId = serverId;
        this.deploySteps = steps;
        this.runUpdate();
    }

    Deployer.prototype.runUpdate = function(fromStep) {
        var self = this;
        $.waterfall.apply(this, this.buildEventChain(this.deploySteps, fromStep))
            .fail(function(reason) {
                var
                    template = $('#executeFailed').html(),
                    html = Mustache.to_html(template, { reason: reason });

                $('#executeActivity').hide();
                $('#executeStatus').html(html);
            });
    }

    Deployer.prototype.retryUpdate = function() {
        $('#executeActivity').show();
        $('#executeStatus').html('');

        this.runUpdate(this.activeStep);
    }

    Deployer.prototype.buildEventChain = function(steps, fromStep) {
        var self = this,
            eventChain = [],
            skipStep = fromStep ? true : false;

        $.each(steps, function(index, step){
            if (step == fromStep) {
                skipStep = false;
            }

            // Continue
            if (skipStep) {
                return true;
            }

            // Pass server ID with step
            step.serverId = self.serverId;
            step.fileMap = self.fileMap;

            eventChain.push(function() {
                var deferred = $.Deferred();

                self.activeStep = step;
                self.setLoadingBar(true, step.label);

                $.request('onExecuteStep', {
                    data: step,
                    progressBar: false,
                    success: function(data) {
                        setTimeout(function() { deferred.resolve() }, 600);

                        // Save remote path against local
                        if (step.action == 'transmitFile') {
                            self.fileMap[step.file] = data.path;
                        }

                        if (step.action == 'final') {
                            this.success(data);
                        }
                        else {
                            self.setLoadingBar(false);
                        }
                    },
                    error: function(data) {
                        self.setLoadingBar(false);
                        deferred.reject(data.responseText);
                    }
                });

                return deferred;
            });
        });

        return eventChain;
    }

    Deployer.prototype.setLoadingBar = function(state, message) {
        var loadingBar = $('#executeLoadingBar'),
            messageDiv = $('#executeMessage');

        if (state) {
            loadingBar.removeClass('bar-loaded');
        }
        else {
            loadingBar.addClass('bar-loaded');
        }

        if (message) {
            messageDiv.text(message);
        }
    }

    if ($.oc === undefined) {
        $.oc = {};
    }

    $.oc.deployer = new Deployer;

}(window.jQuery);
