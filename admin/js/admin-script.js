/* Emetrio Scriptor — Admin Script */

function emetrioScriptorSwitchTab(evt, tabId) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName('emetrio-scriptor-tab-content');
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = 'none';
        tabcontent[i].classList.remove('active');
    }
    tablinks = document.getElementsByClassName('emetrio-scriptor-tab-link');
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove('active');
    }
    document.getElementById(tabId).style.display = 'block';
    document.getElementById(tabId).classList.add('active');
    evt.currentTarget.classList.add('active');

    var url = new URL(window.location.href);
    url.searchParams.set('tab', tabId);
    window.history.pushState({}, '', url);

    var referers = document.getElementsByName('_wp_http_referer');
    for (i = 0; i < referers.length; i++) {
        try {
            var refUrl = new URL(referers[i].value, window.location.origin);
            refUrl.searchParams.set('tab', tabId);
            referers[i].value = refUrl.pathname + refUrl.search;
        } catch(e) {
            if (referers[i].value.indexOf('?') !== -1) {
                var base = referers[i].value.split('?')[0];
                var query = referers[i].value.split('?')[1];
                var params = new URLSearchParams(query);
                params.set('tab', tabId);
                referers[i].value = base + '?' + params.toString();
            } else {
                referers[i].value = referers[i].value + '?tab=' + tabId;
            }
        }
    }
}

function emetrioScriptorCopyToClipboard(text, button) {
    var original = button.textContent;
    navigator.clipboard.writeText(text).then(function() {
        button.textContent = 'Copied';
        setTimeout(function() { button.textContent = original; }, 1500);
    }, function() {
        button.textContent = 'Failed';
        setTimeout(function() { button.textContent = original; }, 1500);
    });
}

function emetrioScriptorShowTestResult(text) {
    document.getElementById('emetrio-scriptor-test-result-box').textContent = text;
    document.getElementById('emetrio-scriptor-test-result').style.display = '';
}

jQuery(document).ready(function($) {

    if (typeof emetrioScriptorData !== 'undefined' && emetrioScriptorData.context === 'admin_page') {

        var $aiBtn     = $('#emetrio-scriptor-ai-gen-btn');
        var $aiLabel   = $aiBtn.find('.emetrio-scriptor-ai-label');
        var $aiSpinner = $('#emetrio-scriptor-ai-inline-spinner');
        var $runBtn    = $('#emetrio-scriptor-run-local-test-btn');

        $aiBtn.on('click', function(e) {
            e.preventDefault();
            var original = $aiLabel.text();
            $aiBtn.prop('disabled', true).attr('aria-disabled', 'true');
            $runBtn.prop('disabled', true).attr('aria-disabled', 'true');
            $aiLabel.text(emetrioScriptorData.i18n.generating);
            $aiSpinner.show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'emetrio_scriptor_generate_ai_preview', _ajax_nonce: emetrioScriptorData.aiNonce },
                success: function(response) {
                    $aiBtn.prop('disabled', false).attr('aria-disabled', 'false');
                    $runBtn.prop('disabled', false).attr('aria-disabled', 'false');
                    $aiLabel.text(original);
                    $aiSpinner.hide();
                    if (response && response.success && response.data) {
                        $('#emetrio-scriptor-ai-gen-msg').text('');
                        $('input[name="test_title"]').val(response.data.title || '');
                        $('textarea[name="test_content"]').val(response.data.content || '');
                        $('input[name="test_excerpt"]').val(response.data.excerpt || '');
                        $('input[name="test_featured_image"]').val(response.data.featured_image_url || '');
                        $('input[name="test_tags"]').val((response.data.tags || []).join(', '));
                        if (response.data.categories && response.data.categories.length) {
                            $('input[name="test_category"]').val(response.data.categories.join(', '));
                        }
                    } else {
                        emetrioScriptorShowTestResult(response && response.data ? response.data : emetrioScriptorData.i18n.aiFailed);
                    }
                },
                error: function() {
                    $aiBtn.prop('disabled', false).attr('aria-disabled', 'false');
                    $runBtn.prop('disabled', false).attr('aria-disabled', 'false');
                    $aiLabel.text(original);
                    $aiSpinner.hide();
                    emetrioScriptorShowTestResult(emetrioScriptorData.i18n.serverError);
                }
            });
        });

        $('form.emetrio-scriptor-card').on('submit', function() {
            try { localStorage.setItem('emetrio_scriptor_active_tab', $('.emetrio-scriptor-tab-content.active').attr('id') || 'settings'); } catch(e) {}
        });

        try {
            var urlParams = new URL(window.location.href).searchParams;
            var urlTab    = urlParams.get('tab');
            var storedTab = localStorage.getItem('emetrio_scriptor_active_tab');
            if (!urlTab && storedTab) {
                var btn = $('.emetrio-scriptor-tab-link').filter(function() { return $(this).attr('onclick').indexOf("'" + storedTab + "'") > -1; }).first();
                if (btn.length) { btn.trigger('click'); }
            } else {
                if (storedTab) { localStorage.removeItem('emetrio_scriptor_active_tab'); }
            }
        } catch(e) {}

        var $freq = $('#emetrio_scriptor_schedule_frequency');
        function toggleRows() {
            var val = $freq.val();
            $('#emetrio-scriptor-weekdays-row').toggle(val === 'weekdays');
            $('#emetrio-scriptor-monthly-row').toggle(val === 'monthly');
            $('#emetrio-scriptor-once-row').toggle(val === 'once');
        }
        if ($freq.length) { $freq.on('change', toggleRows); toggleRows(); }

        var $enabledChk = $('input[name="emetrio_scriptor_schedule_enabled"]');
        function toggleNextRunRow() {
            $('#emetrio-scriptor-next-run-row').toggle($enabledChk.is(':checked'));
        }
        if ($enabledChk.length) { $enabledChk.on('change', toggleNextRunRow); toggleNextRunRow(); }

        var nextRunData = emetrioScriptorData.nextRun;
        var $timer = $('#emetrio-scriptor-countdown-timer');
        var $desc  = $('#emetrio-scriptor-next-run-date-desc');

        if (nextRunData && typeof nextRunData === 'object' && nextRunData.timestamp) {
            var tzName = nextRunData.timezone || 'UTC';
            try {
                var parts = new Intl.DateTimeFormat('en-US', {
                    timeZone: tzName, year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
                }).formatToParts(new Date(nextRunData.timestamp * 1000));
                var p = {};
                parts.forEach(function(part) { p[part.type] = part.value; });
                var formatted = p.day + '-' + p.month + '-' + p.year + ' ' + p.hour + ':' + p.minute + ':' + p.second + ' ' + p.dayPeriod;
                $desc.text(emetrioScriptorData.i18n.targetDate + formatted);
            } catch(e) {
                var d = new Date(nextRunData.timestamp * 1000);
                var pad = function(n) { return String(n).padStart(2, '0'); };
                var fallback = pad(d.getUTCDate()) + '-' + pad(d.getUTCMonth() + 1) + '-' + d.getUTCFullYear() + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
                $desc.text(emetrioScriptorData.i18n.scheduled + fallback);
            }

            function updateCountdown() {
                var targetMs = parseInt(nextRunData.timestamp) * 1000;
                var nowMs    = Date.now();
                var diffSec  = Math.floor((targetMs - nowMs) / 1000);
                if (diffSec <= 60) { $timer.text(emetrioScriptorData.i18n.checkingRun); return; }
                var days    = Math.floor(diffSec / 86400);
                var hours   = Math.floor((diffSec % 86400) / 3600);
                var minutes = Math.floor((diffSec % 3600) / 60);
                var seconds = diffSec % 60;
                var timerStr = '';
                if (days > 0) { timerStr += days + 'd '; }
                timerStr += hours + 'h ' + minutes + 'm ' + seconds + 's';
                $timer.text(timerStr);
            }
            setInterval(updateCountdown, 1000);
            updateCountdown();
        } else {
            $timer.text(emetrioScriptorData.i18n.scheduledPassed);
            $desc.text('');
        }

        var $loadMoreBtn = $('#emetrio-scriptor-load-more-logs');
        $loadMoreBtn.on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            $btn.prop('disabled', true).text(emetrioScriptorData.i18n.loading);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'emetrio_scriptor_load_more_logs', nonce: $btn.data('nonce'), paged: $btn.data('next-page'), row_count: $btn.data('row-count') },
                success: function(response) {
                    if (response && response.success && response.data) {
                        $('#emetrio-scriptor-logs-tbody').append(response.data.html);
                        $btn.data('next-page', response.data.next_page);
                        $btn.data('row-count', response.data.row_count);
                        if (!response.data.has_more) { $btn.hide(); } else { $btn.prop('disabled', false).text(originalText); }
                    } else {
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text(originalText);
                    $('#emetrio-scriptor-load-more-msg').text(emetrioScriptorData.i18n.logsError);
                }
            });
        });

        var emetrioScriptorMediaUploader;
        $('#emetrio-scriptor-media-upload-btn').on('click', function(e) {
            e.preventDefault();
            if (emetrioScriptorMediaUploader) { emetrioScriptorMediaUploader.open(); return; }
            emetrioScriptorMediaUploader = wp.media({
                title: emetrioScriptorData.i18n.selectImage,
                button: { text: emetrioScriptorData.i18n.useImage },
                multiple: false
            });
            emetrioScriptorMediaUploader.on('select', function() {
                var attachment = emetrioScriptorMediaUploader.state().get('selection').first().toJSON();
                $('#emetrio-scriptor-test-featured-image').val(attachment.url);
            });
            emetrioScriptorMediaUploader.open();
        });
    }

    if (typeof emetrioScriptorData !== 'undefined' && emetrioScriptorData.context === 'posts_list') {
        var heading = $('.page-title-action');
        if (heading.length) {
            var tryBtn = $('<a href="' + emetrioScriptorData.adminUrl + '" class="emetrio-scriptor-try-btn dashicons-before dashicons-airplane">' + emetrioScriptorData.i18n.tryScriptor + '</a>');
            heading.after(tryBtn);
        }
    }
});
