/**
 * Krishi Sathi Research System - Interview Draft Auto-Save
 * Phase 1.7: Field Reliability & Data Protection
 *
 * Saves interview form state to localStorage every 30 seconds
 * and on field changes. Restores on revisit with user consent.
 * Protects against accidental navigation, refresh, and tab close.
 */
(function () {
    'use strict';

    var DRAFT_KEY = 'krishi_interview_draft';
    var form = document.getElementById('interviewForm');
    if (!form) return;

    var saveTimer = null;
    var hasDraft = false;

    // ─── CSS.escape polyfill for older browsers ──────────────────
    if (!CSS.escape) {
        CSS.escape = function (value) {
            return String(value).replace(/([!"#$%&'()*+,./:;<=>?@[\]^`{|}~\\])/g, '\\$1');
        };
    }

    // ─── Safe querySelector with name escaping ───────────────────
    function qsName(name) {
        if (!name) return null;
        var escaped = CSS.escape(name);
        var result = form.querySelectorAll('[name="' + escaped + '"]');
        return result.length ? result : null;
    }

    // ─── Serialize all form fields ───────────────────────────────
    function collectFormData() {
        var data = {};
        var elements = form.elements;
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            if (!el.name || el.disabled || el.type === 'file') continue;

            if (el.type === 'checkbox') {
                if (!data[el.name]) data[el.name] = [];
                if (el.checked) data[el.name].push(el.value);
                continue;
            }

            if (el.type === 'radio') {
                if (el.checked) data[el.name] = el.value;
                continue;
            }

            if (el.type === 'select-multiple') {
                var vals = [];
                for (var o = 0; o < el.options.length; o++) {
                    if (el.options[o].selected) vals.push(el.options[o].value);
                }
                data[el.name] = vals;
                continue;
            }

            data[el.name] = el.value;
        }

        // Cascading dropdown visual state
        var iDistrict = document.getElementById('iDistrict');
        var iMunicipality = document.getElementById('iMunicipality');
        var iWard = document.getElementById('iWard');
        data._loc_district_display = iDistrict ? iDistrict.value : '';
        data._loc_municipality_display = iMunicipality ? iMunicipality.value : '';
        data._loc_ward_display = iWard ? iWard.value : '';

        // Timer state
        data._timer_seconds = typeof seconds !== 'undefined' ? seconds : 0;
        data._timer_running = typeof timerRunning !== 'undefined' ? timerRunning : false;

        // GPS data
        var latField = document.getElementById('iLatField');
        var lngField = document.getElementById('iLngField');
        var gpsAltField = document.getElementById('iGpsAltField');
        var altField = form.querySelector('[name="altitude"]');
        data.latitude = latField ? latField.value : '';
        data.longitude = lngField ? lngField.value : '';
        data.gps_altitude = gpsAltField ? gpsAltField.value : '';
        data.altitude = altField ? altField.value : '';

        data._saved_at = new Date().toISOString();
        return data;
    }

    // ─── Save draft to localStorage ──────────────────────────────
    function saveDraft() {
        try {
            var data = collectFormData();
            localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
            hasDraft = true;
            updateDraftIndicator(true);
        } catch (e) {
            // localStorage full or unavailable — silent fail
        }
    }

    // ─── Restore draft to form ───────────────────────────────────
    function restoreDraft(data) {
        if (!data) return;

        for (var key in data) {
            if (key.indexOf('_') === 0) continue;

            var els = qsName(key);
            if (!els || !els.length) continue;

            var el = els[0];
            var val = data[key];

            // Multi-value checkboxes
            if (el.type === 'checkbox' && els.length > 1) {
                for (var j = 0; j < els.length; j++) {
                    els[j].checked = Array.isArray(val) && val.indexOf(els[j].value) !== -1;
                }
                continue;
            }

            // Single checkbox
            if (el.type === 'checkbox') {
                el.checked = (val === true || val === '1' || val === 'on');
                continue;
            }

            // Radio buttons
            if (el.type === 'radio') {
                for (var r = 0; r < els.length; r++) {
                    els[r].checked = (els[r].value === val);
                }
                continue;
            }

            // Select-multiple
            if (el.type === 'select-multiple') {
                for (var s = 0; s < el.options.length; s++) {
                    el.options[s].selected = Array.isArray(val) && val.indexOf(el.options[s].value) !== -1;
                }
                continue;
            }

            // All other fields
            el.value = val !== undefined && val !== null ? val : '';
        }

        // Restore cascading location dropdowns
        if (data._loc_district_display) {
            var iDist = document.getElementById('iDistrict');
            var iMun = document.getElementById('iMunicipality');
            var iWard = document.getElementById('iWard');
            if (iDist) {
                iDist.value = data._loc_district_display;
                if (typeof loadIMunicipalities === 'function') {
                    loadIMunicipalities();
                    if (data._loc_municipality_display) {
                        setTimeout(function () {
                            iMun.value = data._loc_municipality_display;
                            if (typeof loadIWards === 'function') {
                                loadIWards();
                                if (data._loc_ward_display) {
                                    setTimeout(function () {
                                        iWard.value = data._loc_ward_display;
                                    }, 50);
                                }
                            }
                        }, 50);
                    }
                }
            }
        }

        // Restore conditional sections
        triggerConditionalSections(data);

        // Restore GPS status text
        if (data.latitude && data.longitude) {
            var gpsStatus = document.getElementById('iGpsStatus');
            var gpsCoords = document.getElementById('iGpsCoords');
            if (gpsStatus) {
                gpsStatus.innerHTML = 'GPS from draft';
                gpsStatus.className = 'text-success small';
            }
            if (gpsCoords) {
                gpsCoords.innerHTML = data.latitude + ', ' + data.longitude + (data.gps_altitude ? ' (' + data.gps_altitude + 'm)' : '');
            }
            var gpsBtn = document.getElementById('iGpsBtn');
            if (gpsBtn) {
                gpsBtn.innerHTML = '<i class="bi bi-check-circle"></i> GPS Restored';
                gpsBtn.className = 'btn btn-sm btn-success w-100';
            }
        }
    }

    // ─── Helper: trigger change event ────────────────────────────
    function triggerChange(el) {
        if (!el) return;
        var evt = document.createEvent('HTMLEvents');
        evt.initEvent('change', true, true);
        el.dispatchEvent(evt);
    }

    // ─── Trigger conditional sections (voice, reminders, follow-up) ─
    function triggerConditionalSections(data) {
        // Voice section
        var voiceVal = data.voice_interest;
        var voiceReason = document.getElementById('voiceReasonSection');
        var voiceReject = document.getElementById('voiceRejectSection');
        if (voiceReason) voiceReason.style.display = (voiceVal === 'yes' || voiceVal === 'maybe') ? 'block' : 'none';
        if (voiceReject) voiceReject.style.display = voiceVal === 'no' ? 'block' : 'none';

        // Reminder section
        var reminderVal = data.assumption_reminders;
        var reminderSection = document.getElementById('reminderTypesSection');
        if (reminderSection) reminderSection.style.display = (reminderVal === 'yes' || reminderVal === 'maybe') ? 'block' : 'none';

        // Follow-up section
        var furChecked = data.follow_up_required === true || data.follow_up_required === '1' || data.follow_up_required === 'on';
        var fuDetails = document.getElementById('followUpDetails');
        if (fuDetails) fuDetails.style.display = furChecked ? 'block' : 'none';

        // Other text inputs
        toggleOtherInputs(data);
    }

    // ─── Helper: toggle "Other" text inputs ──────────────────────
    function toggleOtherInputs(data) {
        var otherMappings = [
            { input: 'otherProblemInput', key: 'problems[]', val: 'Other' },
            { input: 'lossCausesOtherInput', key: 'loss_causes[]', val: 'Other' },
            { input: 'voiceUCOtherInput', key: 'voice_use_cases[]', val: 'Other' },
            { input: 'motivationOtherInput', key: 'app_motivations[]', val: 'Other' },
            { input: 'recommendationOtherInput', key: 'recommendation_types[]', val: 'Other' },
        ];
        for (var i = 0; i < otherMappings.length; i++) {
            var m = otherMappings[i];
            var vals = data[m.key];
            var checked = Array.isArray(vals) && vals.indexOf(m.val) !== -1;
            var input = document.getElementById(m.input);
            if (input) input.style.display = checked ? 'block' : 'none';
        }
    }

    // ─── Update draft indicator badge ────────────────────────────
    function updateDraftIndicator(saved) {
        var badge = document.getElementById('draftBadge');
        if (!badge) return;
        if (saved) {
            badge.innerHTML = 'Draft saved ' + new Date().toLocaleTimeString();
            badge.className = 'badge bg-success';
        } else {
            badge.innerHTML = 'Unsaved changes';
            badge.className = 'badge bg-warning text-dark';
        }
    }

    // ─── Debounced save on field changes ─────────────────────────
    var changeTimer = null;
    function onFieldChange() {
        if (changeTimer) clearTimeout(changeTimer);
        changeTimer = setTimeout(saveDraft, 500);
    }

    // ─── Check for existing draft on page load ───────────────────
    function checkForDraft() {
        try {
            var raw = localStorage.getItem(DRAFT_KEY);
            if (!raw) return;
            var data = JSON.parse(raw);
            if (!data || !data._saved_at) return;

            var savedAt = new Date(data._saved_at);
            var now = new Date();
            if ((now - savedAt) > 24 * 60 * 60 * 1000) {
                localStorage.removeItem(DRAFT_KEY);
                return;
            }

            showResumeDialog(data);
        } catch (e) {
            // Ignore parse errors
        }
    }

    // ─── Show resume dialog ──────────────────────────────────────
    function showResumeDialog(data) {
        var savedAt = new Date(data._saved_at);
        var timeStr = savedAt.toLocaleString();

        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1050;display:flex;align-items:center;justify-content:center;';
        overlay.id = 'draftOverlay';

        var box = document.createElement('div');
        box.className = 'card shadow border-0';
        box.style.cssText = 'max-width:480px;width:90%;margin:1rem;';

        box.innerHTML =
            '<div class="card-body p-4 text-center">' +
            '<div class="mb-3"><span style="font-size:3rem;">📋</span></div>' +
            '<h5 class="card-title">Unsaved Interview Draft Detected</h5>' +
            '<p class="text-muted small mb-3">A partially completed interview was found from:<br><strong>' + timeStr + '</strong></p>' +
            '<p class="text-muted small">You can continue where you left off, or start fresh.</p>' +
            '<div class="d-flex gap-2 justify-content-center">' +
            '<button id="continueDraftBtn" class="btn btn-success"><i class="bi bi-play-fill"></i> Continue Draft</button>' +
            '<button id="newDraftBtn" class="btn btn-outline-secondary"><i class="bi bi-plus-circle"></i> Start New</button>' +
            '</div>' +
            '</div>';

        overlay.appendChild(box);
        document.body.appendChild(overlay);

        document.getElementById('continueDraftBtn').addEventListener('click', function () {
            document.body.removeChild(overlay);
            restoreDraft(data);
            hasDraft = true;
            updateDraftIndicator(true);
        });

        document.getElementById('newDraftBtn').addEventListener('click', function () {
            document.body.removeChild(overlay);
            localStorage.removeItem(DRAFT_KEY);
            hasDraft = false;
            updateDraftIndicator(false);
        });
    }

    // ─── Clear draft ─────────────────────────────────────────────
    function clearDraft() {
        localStorage.removeItem(DRAFT_KEY);
        hasDraft = false;
    }

    // ─── Initialize ──────────────────────────────────────────────
    function init() {
        var titleEl = document.querySelector('.card-title.mb-1');
        if (titleEl) {
            var badge = document.createElement('span');
            badge.id = 'draftBadge';
            badge.className = 'badge bg-secondary ms-2 small';
            badge.textContent = 'Auto-save active';
            titleEl.parentNode.insertBefore(badge, titleEl.nextSibling);
        }

        setTimeout(checkForDraft, 300);

        saveTimer = setInterval(saveDraft, 30000);

        form.addEventListener('change', onFieldChange);
        form.addEventListener('input', onFieldChange);

        form.addEventListener('submit', function () {
            clearDraft();
        });

        window.addEventListener('beforeunload', function (e) {
            if (hasDraft) {
                e.preventDefault();
                e.returnValue = 'You have unsaved interview data. Are you sure you want to leave?';
                return e.returnValue;
            }
        });

        document.addEventListener('visibilitychange', function () {
            if (document.hidden && hasDraft) {
                saveDraft();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
