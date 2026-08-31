{**
 * Interstitial shown when the customer returns from FLIZpay before the
 * webhook has settled the order. Polls /flizpay/status until the payment is
 * booked, then forwards to the confirmation page.
 *}
<div class="flizpay-return container py-5 text-center"
     id="flizpay-return"
     data-fliz-poll-url="{$flizPollUrl|escape:'html'}"
     data-fliz-status-url="{$flizStatusUrl|escape:'html'}"
     data-fliz-state="{$flizState|escape:'html'}">

    <div class="flizpay-return__pending"{if $flizState !== 'pending'} style="display:none"{/if}>
        {if $flizSpinner}
            <img src="{$flizSpinner|escape:'html'}" alt="" width="64" height="64" class="mb-3">
        {/if}
        <h1 class="h4">{$flizLang.processingHeading|escape:'html'}</h1>
        <p class="text-muted">{$flizLang.processingText|escape:'html'}</p>
        <p class="text-muted flizpay-return__slow" style="display:none">
            {$flizLang.processingSlow|escape:'html'}
        </p>
    </div>

    <div class="flizpay-return__failed"{if $flizState !== 'failed'} style="display:none"{/if}>
        <h1 class="h4">{$flizLang.failedHeading|escape:'html'}</h1>
        <p class="text-muted">{$flizLang.failedText|escape:'html'}</p>
    </div>

    <p class="mt-4">
        <a href="{$flizStatusUrl|escape:'html'}" class="btn btn-outline-secondary">
            {$flizLang.toOrderStatus|escape:'html'}
        </a>
    </p>
</div>

<script>
{literal}
(function () {
    var root = document.getElementById('flizpay-return');
    if (!root || root.getAttribute('data-fliz-state') !== 'pending') {
        return;
    }
    var pollUrl = root.getAttribute('data-fliz-poll-url');
    var started = Date.now();
    var timer = null;

    function stop() {
        if (timer) { window.clearTimeout(timer); timer = null; }
    }

    function showSlowNotice() {
        var slow = root.querySelector('.flizpay-return__slow');
        if (slow) { slow.style.display = ''; }
    }

    function showFailed(statusUrl) {
        stop();
        var pending = root.querySelector('.flizpay-return__pending');
        var failed = root.querySelector('.flizpay-return__failed');
        if (pending) { pending.style.display = 'none'; }
        if (failed) { failed.style.display = ''; }
        if (statusUrl) { root.setAttribute('data-fliz-status-url', statusUrl); }
    }

    function schedule() {
        var elapsed = Date.now() - started;
        if (elapsed > 90000) {
            stop();
            showSlowNotice();
            return;
        }
        timer = window.setTimeout(poll, elapsed > 30000 ? 5000 : 2000);
    }

    function poll() {
        fetch(pollUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data) { schedule(); return; }
                if (data.state === 'completed' && data.redirectUrl) {
                    stop();
                    window.location.replace(data.redirectUrl);
                    return;
                }
                if (data.state === 'failed') {
                    showFailed(data.statusUrl);
                    return;
                }
                schedule();
            })
            .catch(function () { schedule(); });
    }

    schedule();
}());
{/literal}
</script>
