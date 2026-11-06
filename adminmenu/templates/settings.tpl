{if $flizAdminCssUrl !== ''}
    <link rel="stylesheet" href="{$flizAdminCssUrl|escape:'html'}">
{/if}

{foreach $flizMessages as $message}
    <div class="alert alert-{$message.type|escape:'html'}">{$message.text|escape:'html'}</div>
{/foreach}

<form method="post">
    {$flizTokenInput nofilter}

    <div class="card mb-4">
        <div class="card-header">{d__('flizpay', 'Welcome to FLIZpay!')}</div>
        <div class="card-body">
            <p><strong>{d__('flizpay', 'Instructions:')}</strong></p>
            <ol class="mb-3">
                <li>
                    {d__('flizpay', "If you don't yet have a FLIZpay business account, please")}
                    <a href="https://app.flizpay.de/auth/signup" target="_blank" rel="noopener">{d__('flizpay', 'click here')}</a>
                    {d__('flizpay', 'to create one.')}
                </li>
                <li>{d__('flizpay', 'Generate an API key in your FLIZpay business account under "Set up FLIZ" -> "Plugins" -> "JTL" and paste it into the "API Key" field below.')}</li>
                <li>{d__('flizpay', 'Click "Save".')}</li>
                <li>{d__('flizpay', 'The page reloads automatically every few seconds while waiting for the FLIZpay test notification.')}</li>
                <li>{d__('flizpay', 'As soon as a green confirmation message appears, the plugin is fully configured.')}</li>
                <li>
                    {d__('flizpay', 'Assign FLIZpay to your shipping methods and customer groups:')}
                    <a href="{$flizShippingMethodsUrl|escape:'html'}">{d__('flizpay', 'Go to Shipping Methods')}</a>
                </li>
                <li>{d__('flizpay', 'If you use JTL-Wawi: create a payment method named exactly "FLIZpay" in JTL-Wawi under "Zahlungen" -> "Zahlungsarten". JTL-Wawi links shop and Wawi payment methods automatically when the names match exactly. For multi-language shops, also set the name for every language. Otherwise imported orders and payments are not assigned to a payment method in JTL-Wawi and invoices show no payment method.')}</li>
                <li>
                    {d__('flizpay', 'We recommend activating discounts for your customers in your')}
                    <a href="https://app.flizpay.de/discount" target="_blank" rel="noopener">{d__('flizpay', 'FLIZpay Dashboard')}</a>.
                </li>
            </ol>
            <p class="mb-1">
                {d__('flizpay', 'Still have questions? Check out our')}
                <a href="https://flizpay.de/businesses#how-do-i-install-fliz" target="_blank" rel="noopener">{d__('flizpay', 'FAQ page')}</a>.
            </p>
            <p class="mb-0">
                {d__('flizpay', 'Need more details? Read our')}
                <a href="https://www.docs.flizpay.de/docs/intro" target="_blank" rel="noopener">{d__('flizpay', 'documentation')}</a>.
            </p>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">{d__('flizpay', 'API Configuration')}</div>
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
        <div class="card-header">{d__('flizpay', 'Checkout Settings')}</div>
        <div class="card-body">
            <div class="fliz-checkout-preview" id="flizCheckoutPreview">
                {if !$flizHasPublicId}
                    <div class="alert alert-warning fliz-checkout-preview__hint--warning">
                        {d__('flizpay', 'Save your FLIZpay API key to load the checkout preview.')}
                    </div>
                {/if}
                <div class="fliz-checkout-preview__box">
                    <div class="fliz-checkout-preview__row">
                        <div class="fliz-checkout-preview__radio"></div>
                        {* The plain name shows until the hosted script upgrades the element. *}
                        <span class="fliz-checkout-preview__name" id="flizPreviewTitle">{if $flizHasPublicId && $flizDisplayHeadline}{$flizPreviewTitleHtml nofilter}{else}FLIZpay{/if}</span>
                        {if $flizLogoUrl !== ''}
                            <span class="fliz-checkout-preview__logo"
                                  id="flizPreviewLogo"
                                  {if !$flizDisplayLogo}hidden{/if}>
                                <img src="{$flizLogoUrl|escape:'html'}" alt="FLIZpay Logo" width="80" height="28">
                            </span>
                        {/if}
                    </div>
                    {if $flizHasPublicId}
                        <div class="fliz-checkout-preview__subtitle"
                             id="flizPreviewSubtitle"
                             {if !$flizDisplayDescription}hidden{/if}>{$flizPreviewDescriptionHtml nofilter}</div>
                    {/if}
                </div>
                <div class="fliz-checkout-preview__labels">
                    <span class="fliz-checkout-preview__label"
                          id="flizLabelHeadline"
                          {if !$flizDisplayHeadline}hidden{/if}>{d__('flizpay', 'Headline')}</span>
                    <span class="fliz-checkout-preview__label"
                          id="flizLabelLogo"
                          {if !$flizDisplayLogo}hidden{/if}>{d__('flizpay', 'Logo')}</span>
                    <span class="fliz-checkout-preview__label"
                          id="flizLabelSubtitle"
                          {if !$flizDisplayDescription}hidden{/if}>{d__('flizpay', 'Subtitle')}</span>
                </div>
                {if $flizHasPublicId}
                    <p class="fliz-checkout-preview__hint">
                        {$flizPreviewHint|escape:'html'}
                    </p>
                    {$flizWidgetScriptHtml nofilter}
                {/if}
            </div>

            <div class="fliz-checkout-settings">
                <div class="fliz-checkout-settings__option">
                    <span class="fliz-checkout-settings__name">{d__('flizpay', 'Headline')}</span>
                    <input type="hidden" name="flizpay_displayHeadline" value="N">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="flizDisplayHeadlineInput"
                               name="flizpay_displayHeadline"
                               value="Y"
                               {if $flizDisplayHeadline}checked{/if}>
                        <label class="custom-control-label" for="flizDisplayHeadlineInput">
                            {d__('flizpay', 'Show the FLIZpay offer instead of the payment method name')}
                        </label>
                    </div>
                </div>
                <div class="fliz-checkout-settings__option">
                    <span class="fliz-checkout-settings__name">{d__('flizpay', 'Logo')}</span>
                    <input type="hidden" name="flizpay_displayLogo" value="N">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="flizDisplayLogoInput"
                               name="flizpay_displayLogo"
                               value="Y"
                               {if $flizDisplayLogo}checked{/if}>
                        <label class="custom-control-label" for="flizDisplayLogoInput">
                            {d__('flizpay', 'Show FLIZpay logo in checkout')}
                        </label>
                    </div>
                </div>
                <div class="fliz-checkout-settings__option">
                    <span class="fliz-checkout-settings__name">{d__('flizpay', 'Subtitle')}</span>
                    <input type="hidden" name="flizpay_displayDescription" value="N">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="flizDisplayDescriptionInput"
                               name="flizpay_displayDescription"
                               value="Y"
                               {if $flizDisplayDescription}checked{/if}>
                        <label class="custom-control-label" for="flizDisplayDescriptionInput">
                            {d__('flizpay', 'Show description in subtitle when FLIZpay is selected')}
                        </label>
                    </div>
                </div>
            </div>
            <small class="form-text text-muted mt-3 mb-0">
                {d__('flizpay', 'The checkout text is provided by FLIZpay and always reflects the discounts configured in your FLIZ company account.')}
            </small>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">{d__('flizpay', 'Payment Options')}</div>
        <div class="card-body">
            <input type="hidden" name="flizpay_debugMode" value="N">
            <div class="custom-control custom-checkbox">
                <input type="checkbox"
                       class="custom-control-input"
                       id="flizDebugModeInput"
                       name="flizpay_debugMode"
                       value="Y"
                       {if $flizDebugMode}checked{/if}>
                <label class="custom-control-label" for="flizDebugModeInput">
                    {d__('flizpay', 'Enable debug logging')}
                </label>
            </div>
            <small class="form-text text-muted">
                {d__('flizpay', 'Warnings and errors are always written to the log file. Enable to also record debug entries (API calls, webhooks, payment steps). API keys, signatures and customer data are never logged. Disable when you are done.')}
            </small>
            <p class="mb-0 mt-2 small">
                {d__('flizpay', 'Log file')}: <code>{$flizLogFile|escape:'html'}</code>
                {if $flizPaymentLogUrl !== ''}
                    &middot; <a href="{$flizPaymentLogUrl|escape:'html'}">{d__('flizpay', 'Open FLIZpay payment log')}</a>
                {/if}
            </p>
        </div>
    </div>

    <div class="mb-4 text-right">
        <button type="submit" name="flizAction" value="save" class="btn btn-primary">
            {d__('flizpay', 'Save')}
        </button>
    </div>
</form>

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

    // Live checkout preview: each checkbox instantly shows/hides its preview
    // element and the matching pointer label; persisted only on Save.
    (function () {
        var headline = document.getElementById('flizDisplayHeadlineInput');
        var title    = document.getElementById('flizPreviewTitle');
        var label    = document.getElementById('flizLabelHeadline');
        var titleHtml = {/literal}{$flizPreviewTitleJson nofilter}{literal};
        if (headline && title) {
            headline.addEventListener('change', function () {
                if (label) {
                    label.hidden = !headline.checked;
                }
                if (titleHtml === '') {
                    return;
                }
                // Only the headline node is replaced, so the description
                // element stays mounted and never refetches its offer text.
                if (headline.checked) {
                    title.innerHTML = titleHtml;
                } else {
                    title.textContent = 'FLIZpay';
                }
            });
        }
    })();

    (function () {
        [
            ['flizDisplayLogoInput', ['flizPreviewLogo', 'flizLabelLogo']],
            ['flizDisplayDescriptionInput', ['flizPreviewSubtitle', 'flizLabelSubtitle']]
        ].forEach(function (binding) {
            var checkbox = document.getElementById(binding[0]);
            if (!checkbox) {
                return;
            }
            checkbox.addEventListener('change', function () {
                binding[1].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) {
                        el.hidden = !checkbox.checked;
                    }
                });
            });
        });
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
