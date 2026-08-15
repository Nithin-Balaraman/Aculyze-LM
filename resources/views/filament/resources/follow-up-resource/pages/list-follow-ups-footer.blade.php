{{--
    Phase 2 item #3: Filament's table grouping has no "collapsed by
    default" option (Group::collapsible() only adds the toggle — the
    initial state is always expanded, tracked client-side in an Alpine
    `collapsedGroups` array that starts empty). Rather than eject/override
    the vendor group-header view (which would affect every grouped table
    app-wide, not just this page), this simulates a click on every
    currently-expanded group header so the *existing* toggle mechanism
    collapses itself — same end state a user clicking each one would
    produce, just done automatically.

    A MutationObserver — not a fixed set of "when to check" events — is
    what actually makes this reliable: Filament tables load in two stages
    (an initial skeleton, per the `wire:init="loadTable"` in
    filament/tables' index.blade.php, then the real rows arrive a moment
    later via their own Livewire request), and switching tabs is a third,
    separate render. Trying to enumerate each of those moments individually
    (an `alpine:initialized` listener alone only ever caught the empty
    skeleton, never the real content that shows up after it) is exactly the
    kind of timing assumption that breaks under Filament's actual loading
    sequence. Watching the DOM directly reacts to group headers whenever
    they genuinely appear, regardless of which of those stages produced
    them.

    The click-toggle itself is idempotent (skips anything already showing
    aria-expanded="false"), so the observer re-firing on its own resulting
    mutations (collapsing a group hides its rows, which is itself a
    mutation) settles after one real pass per header rather than looping.
--}}
<script>
    (function () {
        function collapseHeaderIfExpanded(header) {
            var toggle = header.querySelector('button[aria-expanded]');

            if (toggle && toggle.getAttribute('aria-expanded') === 'false') {
                return;
            }

            header.click();
        }

        function collapseExpandedFollowUpGroups() {
            document.querySelectorAll('.fi-ta-group-header').forEach(collapseHeaderIfExpanded);
        }

        new MutationObserver(collapseExpandedFollowUpGroups).observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
        });

        // Covers the case where the table is already fully rendered by
        // the time this script runs (e.g. no deferred loading in play).
        collapseExpandedFollowUpGroups();
    })();
</script>
