<style>
{literal}
    html,
    body {
        min-height: 100%;
        margin: 0;
    }

    .flizpay-return {
        box-sizing: border-box;
        display: flex;
        min-height: 100vh;
        min-height: 100dvh;
        align-items: center;
        justify-content: center;
        padding: 2rem 1.25rem;
        color: #001f3f;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        text-align: center;
    }

    .flizpay-return__content {
        width: 100%;
        max-width: 38rem;
    }

    .flizpay-return__spinner {
        display: block;
        width: 50px;
        height: 50px;
        margin: 0 auto 1.5rem;
        color: #80ed99;
        animation: flizpay-spin 2s linear infinite;
    }

    .flizpay-return h1 {
        margin: 0 0 0.75rem;
        font-size: clamp(1.5rem, 4vw, 2rem);
        line-height: 1.2;
    }

    .flizpay-return p {
        margin: 0;
        font-size: 1rem;
        line-height: 1.6;
    }

    .flizpay-return__slow {
        margin-top: 0.75rem !important;
    }

    .flizpay-return__action {
        display: inline-block;
        margin-top: 2rem;
        padding: 0.75rem 1.25rem;
        border: 1px solid #22577a;
        border-radius: 0.5rem;
        color: #22577a;
        font-weight: 600;
        text-decoration: none;
    }

    .flizpay-return__action:hover,
    .flizpay-return__action:focus-visible {
        background: #22577a;
        color: #fff;
    }

    @keyframes flizpay-spin {
        to { transform: rotate(360deg); }
    }
{/literal}
</style>

<div class="flizpay-return"
     id="flizpay-return"
     data-fliz-poll-url="{$flizPollUrl|escape:'html'}"
     data-fliz-status-url="{$flizStatusUrl|escape:'html'}"
     data-fliz-state="{$flizState|escape:'html'}">
    <div class="flizpay-return__content">
        <div class="flizpay-return__pending" role="status"{if $flizState !== 'pending'} style="display:none"{/if}>
            <svg class="flizpay-return__spinner" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
                <path opacity="0.2" fill-rule="evenodd" clip-rule="evenodd" d="M12 19C15.866 19 19 15.866 19 12C19 8.13401 15.866 5 12 5C8.13401 5 5 8.13401 5 12C5 15.866 8.13401 19 12 19ZM12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fill="currentColor" />
                <path d="M2 12C2 6.47715 6.47715 2 12 2V5C8.13401 5 5 8.13401 5 12H2Z" fill="currentColor" />
            </svg>
            <h1>{$flizLang.processingHeading|escape:'html'}</h1>
            <p>{$flizLang.processingText|escape:'html'}</p>
            <p class="flizpay-return__slow" style="display:none">
                {$flizLang.processingSlow|escape:'html'}
            </p>
        </div>

        <div class="flizpay-return__failed" role="status"{if $flizState !== 'failed'} style="display:none"{/if}>
            <h1>{$flizLang.failedHeading|escape:'html'}</h1>
            <p>{$flizLang.failedText|escape:'html'}</p>
        </div>

        <a href="{$flizStatusUrl|escape:'html'}" class="flizpay-return__action">
            {$flizLang.toOrderStatus|escape:'html'}
        </a>
    </div>
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
