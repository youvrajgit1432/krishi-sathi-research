/**
 * Krishi Sathi Research System — Unlock Modal Behaviour
 * ROLE-ACCESS-01.2: Shared category toggle and reason character counter.
 *
 * Included by interview-view.php and participant-interview-view.php.
 * Requires Bootstrap modal with:
 *   - #unlockCategory  (select, triggers "Other" field visibility)
 *   - #otherCategoryField (div, shown/hidden)
 *   - #unlockReason  (textarea, maxlength=500, minlength=10)
 *   - #reasonCharCount (small, updated on input)
 *
 * No configuration needed — auto-activates on DOMContentLoaded.
 */
(function () {
    'use strict';

    function init() {
        var catSelect = document.getElementById('unlockCategory');
        var otherField = document.getElementById('otherCategoryField');
        var reasonText = document.getElementById('unlockReason');
        var charCount = document.getElementById('reasonCharCount');

        if (catSelect && otherField) {
            catSelect.addEventListener('change', function () {
                otherField.style.display = this.value === 'Other' ? 'block' : 'none';
            });
        }

        if (reasonText && charCount) {
            reasonText.addEventListener('input', function () {
                var len = this.value.length;
                charCount.textContent = len + ' / 500';
                charCount.className = len < 10 ? 'text-danger' : 'text-success';
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
