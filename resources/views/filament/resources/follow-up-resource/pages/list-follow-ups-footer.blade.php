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

    Runs once after Alpine's initial page walk completes (`aria-expanded`
    only reflects real state once Alpine has processed the group headers'
    x-bind directives), and again whenever FollowUpResource\Pages\
    ListFollowUps::updatedActiveTab() dispatches `follow-ups-grouping-reset`
    after a tab switch — since $tableGrouping/$activeTab changing doesn't
    reset the client-side collapsedGroups array on its own, and switching
    to History/Lost should start collapsed each time, not carry over
    whatever was manually expanded on a previous visit to that tab.
--}}
<script>
    (function () {
        function collapseExpandedFollowUpGroups() {
            document.querySelectorAll('.fi-ta-group-header').forEach(function (header) {
                var toggle = header.querySelector('button[aria-expanded]');

                if (toggle && toggle.getAttribute('aria-expanded') === 'false') {
                    return;
                }

                header.click();
            });
        }

        document.addEventListener('alpine:initialized', collapseExpandedFollowUpGroups);
        window.addEventListener('follow-ups-grouping-reset', collapseExpandedFollowUpGroups);
    })();
</script>
