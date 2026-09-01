{foreach $flizMessages as $message}
    <div class="alert alert-{$message.type|escape:'html'}">{$message.text|escape:'html'}</div>
{/foreach}

<form method="post">
    {$flizTokenInput nofilter}

    <div class="card mb-4">
        <div class="card-body">
            <div class="form-group">
                <label for="flizApiKeyInput">API-Key</label>
                <div class="input-group">
                    <input type="password"
                           class="form-control"
                           id="flizApiKeyInput"
                           name="flizpay_apiKey"
                           value="{$flizApiKeyMask|escape:'html'}"
                           autocomplete="off"
                           spellcheck="false">
                    <div class="input-group-append">
                        <button type="button"
                                class="btn btn-outline-secondary"
                                id="flizApiKeyToggle"
                                title="{d__('flizpay', 'Show or hide the API key')}">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <small class="form-text text-muted">
                    {d__('flizpay', 'You can find your API key in the FLIZ company account under "Set up FLIZ" -> "Plugins" -> "JTL". After saving, the plugin automatically registers the webhook URL and verifies the connection below.')}
                </small>
            </div>

            {if $flizConnected}
                <div class="alert alert-success mb-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    {d__('flizpay', 'Connection established! Our servers have successfully communicated with your site. You are now ready to accept fee-free payments!')}
                </div>
            {elseif !$flizApiKeySet}
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    <span>
                        {d__('flizpay', 'First enter your API key above and save.')}
                        {d__('flizpay', 'FLIZpay is not offered at checkout until the connection is complete.')}
                    </span>
                </div>
            {elseif $flizAwaitingTest}
                <div class="alert alert-info mb-3">
                    <span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span>
                    {d__('flizpay', 'Waiting for the FLIZpay test notification. The shop must be publicly accessible (no password protection, no IP restriction, and a valid SSL certificate).')}
                </div>
            {else}
                <div class="alert alert-warning mb-3">
                    {d__('flizpay', 'FLIZpay is not offered at checkout until the connection is complete.')}
                    {if !$flizWebhookKeySet}
                        {d__('flizpay', 'The webhook key is missing. Please save the settings again.')}
                    {elseif !$flizWebhookAlive}
                        {d__('flizpay', 'Waiting for the FLIZpay test notification. The shop must be publicly accessible (no password protection, no IP restriction, and a valid SSL certificate).')}
                    {/if}
                </div>
            {/if}

        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">{d__('flizpay', 'Checkout display')}</div>
        <div class="card-body">
            <div class="form-group">
                <label for="flizDisplayLogoInput">{d__('flizpay', 'Show FLIZpay logo')}</label>
                <select class="custom-select" id="flizDisplayLogoInput" name="flizpay_displayLogo">
                    <option value="Y"{if $flizDisplayLogo} selected{/if}>{d__('flizpay', 'Yes')}</option>
                    <option value="N"{if !$flizDisplayLogo} selected{/if}>{d__('flizpay', 'No')}</option>
                </select>
            </div>
            <div class="form-group">
                <label for="flizDisplayHeadlineInput">{d__('flizpay', 'Show discount in the title ("FLIZpay - Up to X% discount")')}</label>
                <select class="custom-select" id="flizDisplayHeadlineInput" name="flizpay_displayHeadline">
                    <option value="Y"{if $flizDisplayHeadline} selected{/if}>{d__('flizpay', 'Yes')}</option>
                    <option value="N"{if !$flizDisplayHeadline} selected{/if}>{d__('flizpay', 'No')}</option>
                </select>
                <small class="form-text text-muted">
                    {d__('flizpay', 'The discount value is retrieved from your FLIZ company account and kept up to date automatically.')}
                </small>
            </div>
            <div class="form-group mb-0">
                <label for="flizDisplayDescriptionInput">{d__('flizpay', 'Show description')}</label>
                <select class="custom-select" id="flizDisplayDescriptionInput" name="flizpay_displayDescription">
                    <option value="Y"{if $flizDisplayDescription} selected{/if}>{d__('flizpay', 'Yes')}</option>
                    <option value="N"{if !$flizDisplayDescription} selected{/if}>{d__('flizpay', 'No')}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="mb-4 text-right">
        <button type="submit" name="flizAction" value="save" class="btn btn-primary">
            {d__('flizpay', 'Save')}
        </button>
    </div>
</form>

<div class="card">
    <div class="card-header">{d__('flizpay', 'Open FLIZpay payments')}</div>
    <div class="card-body p-0">
        {if $flizOpenPayments|@count === 0}
            <p class="text-muted p-3 mb-0">{d__('flizpay', 'There are currently no open FLIZpay payments.')}</p>
        {else}
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>{d__('flizpay', 'Order')}</th>
                            <th>{d__('flizpay', 'Created')}</th>
                            <th>{d__('flizpay', 'Attempt')}</th>
                            <th>{d__('flizpay', 'Transaction')}</th>
                            <th>Status</th>
                            <th>{d__('flizpay', 'Amount')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $flizOpenPayments as $payment}
                            <tr>
                                <td>{$payment->cBestellNr|escape:'html'}</td>
                                <td>{$payment->dCreated|escape:'html'}</td>
                                <td>{$payment->nAttempt|escape:'html'}</td>
                                <td><code class="small">{$payment->cTransactionId|default:'—'|escape:'html'}</code></td>
                                <td>{$payment->txStatus|default:'—'|escape:'html'}</td>
                                <td>
                                    {if $payment->fOriginalAmount}
                                        {$payment->fOriginalAmount|string_format:"%.2f"} {$payment->cCurrency|escape:'html'}
                                    {else}—{/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {/if}
    </div>
</div>

<script>
    {literal}
    (function () {
        var toggle = document.getElementById('flizApiKeyToggle');
        var input  = document.getElementById('flizApiKeyInput');
        if (toggle && input) {
            toggle.addEventListener('click', function () {
                var hidden  = input.type === 'password';
                input.type  = hidden ? 'text' : 'password';
                var icon = toggle.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', !hidden);
                    icon.classList.toggle('fa-eye-slash', hidden);
                }
            });
        }
    })();
    {/literal}
</script>

{if $flizAwaitingTest}
    <script>
        {literal}
        window.setTimeout(function () { window.location.reload(); }, 5000);
        {/literal}
    </script>
{/if}
