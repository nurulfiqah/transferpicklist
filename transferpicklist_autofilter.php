<?php
// Shared "auto-apply filters" behaviour for every Transfer Pick List page.
// Included once by transferpicklist_sidebar.php (so every page that shows the
// sidebar gets it) and directly by pages without the sidebar.
//
// Applies ONLY to GET filter forms (<form method="get">). POST forms (adding
// exclusions, imports, Generate, Confirm All) are never auto-submitted.
//   - <select>, date/month inputs, checkboxes: apply as soon as they change
//   - text / search / number inputs: apply ~1s after typing stops (Enter = now)
// Opt a form out with data-no-autofilter, or a field with data-no-autofilter.
// A <select> that already has its own onchange handler is left alone.
if (defined('TPL_AUTOFILTER_LOADED')) { return; }
define('TPL_AUTOFILTER_LOADED', true);
?>
<script>
(function() {
    function init() {
        var forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            var method = (form.getAttribute('method') || 'get').toLowerCase();
            if (method !== 'get' || form.hasAttribute('data-no-autofilter')) { return; }

            function apply() {
                if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
            }

            form.querySelectorAll('select, input').forEach(function(field) {
                if (field.hasAttribute('data-no-autofilter') || field.disabled) { return; }
                var type = (field.getAttribute('type') || 'text').toLowerCase();

                if (field.tagName === 'SELECT' || type === 'date' || type === 'month' || type === 'checkbox' || type === 'radio') {
                    if (field.hasAttribute('onchange')) { return; } // page already handles it
                    field.addEventListener('change', apply);
                    return;
                }

                if (type === 'text' || type === 'search' || type === 'number') {
                    var timer = null, last = field.value;
                    field.addEventListener('input', function() {
                        clearTimeout(timer);
                        timer = setTimeout(function() {
                            if (field.value.trim() !== last.trim()) { last = field.value; apply(); }
                        }, 1000);
                    });
                }
            });

            // After a search reload, put the cursor back at the end of the box that has text,
            // so the user can keep typing without clicking it again.
            var typed = form.querySelector('input[type="text"]:not([data-no-autofilter]), input[type="search"]:not([data-no-autofilter]), input:not([type]):not([data-no-autofilter])');
            if (typed && typed.value !== '' && document.activeElement === document.body) {
                typed.focus();
                var len = typed.value.length;
                try { typed.setSelectionRange(len, len); } catch (e) {}
            }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>
