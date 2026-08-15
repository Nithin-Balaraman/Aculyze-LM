{{--
    TEMPORARY DIAGNOSTIC BUILD (Phase 2 item #3 — "collapsed by default"
    debugging). Two attempts (alpine:initialized alone, then a
    MutationObserver) both failed on a genuine fresh page load in the
    browser despite reasoning correctly from Filament's source each time,
    so this replaces the fix with an on-page, always-visible log instead
    of guessing a fourth time. It answers, in order:

    1. Does this script even run on a true fresh load, and when.
    2. Does the MutationObserver attach and actually fire.
    3. When it fires, how many .fi-ta-group-header elements exist, and for
       each one: is the aria-expanded toggle button found at all, what
       does it read, and does that value change immediately after we
       call header.click() (checked both synchronously and 100ms later,
       to catch Alpine processing the click's effect asynchronously).

    Remove this whole file's contents (revert to the plain collapse
    script) once the real cause is found — do not ship this diagnostic
    version.
--}}
<script>
    (function () {
        var panel = document.createElement('div');
        panel.id = 'fu-group-debug';
        panel.style.cssText = 'position:fixed;bottom:0;right:0;width:420px;max-height:60vh;overflow-y:auto;'
            + 'background:#111;color:#0f0;font:11px/1.4 monospace;padding:8px;z-index:99999;'
            + 'border:2px solid #f90;white-space:pre-wrap;';
        document.body.appendChild(panel);

        var count = 0;

        function log(message) {
            count += 1;
            var line = document.createElement('div');
            line.textContent = '[' + count + '] ' + (performance.now() | 0) + 'ms — ' + message;
            panel.appendChild(line);
            panel.scrollTop = panel.scrollHeight;
            console.log('[fu-group-debug]', message);
        }

        log('script executed. document.readyState=' + document.readyState);

        function describeHeader(header, index) {
            var titleEl = header.querySelector('h4');
            var title = titleEl ? titleEl.textContent.trim() : '(no h4 found)';
            var toggle = header.querySelector('button[aria-expanded]');

            if (!toggle) {
                log('header #' + index + ' "' + title + '": NO button[aria-expanded] found. header.outerHTML(first 200 chars)=' + header.outerHTML.slice(0, 200));

                return;
            }

            var before = toggle.getAttribute('aria-expanded');
            log('header #' + index + ' "' + title + '": aria-expanded BEFORE click=' + before);

            if (before === 'false') {
                log('header #' + index + ' "' + title + '": already collapsed, skipping click.');

                return;
            }

            header.click();

            var afterSync = toggle.getAttribute('aria-expanded');
            log('header #' + index + ' "' + title + '": aria-expanded immediately AFTER click (sync)=' + afterSync);

            setTimeout(function () {
                var afterDelay = toggle.getAttribute('aria-expanded');
                var rowHidden = null;
                var nextRow = header.closest('tr') ? header.closest('tr').nextElementSibling : header.nextElementSibling;
                if (nextRow) {
                    rowHidden = nextRow.hasAttribute('hidden') || getComputedStyle(nextRow).display === 'none';
                }
                log('header #' + index + ' "' + title + '": aria-expanded 100ms AFTER click=' + afterDelay + ', next row hidden=' + rowHidden);
            }, 100);
        }

        function sweep(reason) {
            var headers = document.querySelectorAll('.fi-ta-group-header');
            log('sweep (' + reason + '): found ' + headers.length + ' .fi-ta-group-header element(s).');
            headers.forEach(describeHeader);
        }

        var observer = new MutationObserver(function (mutations) {
            log('MutationObserver callback fired. mutations.length=' + mutations.length);
            sweep('mutation observer');
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
        });

        log('MutationObserver attached to document.body.');

        sweep('initial synchronous run');
    })();
</script>
