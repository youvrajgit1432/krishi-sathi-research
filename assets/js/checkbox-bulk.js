/**
 * Checkbox Bulk Selection Controls
 * Generic, reusable — works for any form with checkbox groups (name="fieldname[]").
 * Adds "Select All" / "Clear All" buttons above each multi-checkbox group.
 *
 * Usage:
 *   <script src="path/to/checkbox-bulk.js"></script>
 *
 * For dynamic content (e.g., AJAX-loaded checkboxes), call:
 *   initCheckboxBulkControls();
 *
 * No configuration needed — auto-detects checkbox groups by name attribute.
 */
(function () {
  'use strict';

  var SELECTOR = 'input[type="checkbox"][name$="[]"]';

  /**
   * Find the nearest common ancestor element that contains all given nodes.
   */
  function getCommonAncestor(nodes) {
    if (!nodes || nodes.length === 0) return null;
    var ancestor = nodes[0].parentElement;
    while (ancestor) {
      var allContained = true;
      for (var i = 0; i < nodes.length; i++) {
        if (!ancestor.contains(nodes[i])) {
          allContained = false;
          break;
        }
      }
      if (allContained) break;
      ancestor = ancestor.parentElement;
    }
    return ancestor;
  }

  /**
   * Check if bulk controls already exist for the given container.
   */
  function hasBulkControls(container) {
    var prev = container.previousElementSibling;
    return prev && prev.classList.contains('cb-bulk-controls');
  }

  /**
   * Initialize bulk controls for all checkbox groups in the document.
   * Safe to call multiple times — skips groups that already have controls.
   */
  function initCheckboxBulkControls() {
    // Group checkboxes by name
    var groups = {};
    var checkboxes = document.querySelectorAll(SELECTOR);
    for (var i = 0; i < checkboxes.length; i++) {
      var cb = checkboxes[i];
      var name = cb.name;
      if (!groups[name]) groups[name] = [];
      groups[name].push(cb);
    }

    // Process each group
    var names = Object.keys(groups);
    for (var n = 0; n < names.length; n++) {
      var groupName = names[n];
      var cbs = groups[groupName];
      if (cbs.length < 2) continue; // skip single checkboxes

      var container = getCommonAncestor(cbs);
      if (!container || hasBulkControls(container)) continue;

      // Skip if container is inside a card-body but is the card-body itself
      // We want the inner container (the .row or .d-flex that holds checkboxes)
      // But if no inner container, the card-body is fine

      // Create controls bar
      var controls = document.createElement('div');
      controls.className = 'cb-bulk-controls d-flex gap-2 mb-1';
      controls.setAttribute('role', 'group');
      controls.setAttribute('aria-label', 'Bulk checkbox actions for ' + groupName);

      var selectBtn = document.createElement('button');
      selectBtn.type = 'button';
      selectBtn.className = 'btn btn-sm btn-outline-success cb-bulk-select';
      selectBtn.setAttribute('data-group', groupName);
      selectBtn.setAttribute('aria-label', 'Select all in this group');
      selectBtn.innerHTML = '<i class="bi bi-check-all"></i> Select All';

      var clearBtn = document.createElement('button');
      clearBtn.type = 'button';
      clearBtn.className = 'btn btn-sm btn-outline-secondary cb-bulk-clear';
      clearBtn.setAttribute('data-group', groupName);
      clearBtn.setAttribute('aria-label', 'Clear all in this group');
      clearBtn.innerHTML = '<i class="bi bi-x"></i> Clear All';

      controls.appendChild(selectBtn);
      controls.appendChild(clearBtn);

      container.parentElement.insertBefore(controls, container);
    }
  }

  /**
   * Handle bulk button clicks via event delegation.
   */
  function handleBulkClick(e) {
    var btn = e.target.closest('.cb-bulk-select, .cb-bulk-clear');
    if (!btn) return;

    e.preventDefault();

    var groupName = btn.getAttribute('data-group');
    var isSelect = btn.classList.contains('cb-bulk-select');

    // Escape special characters in name for querySelector
    var escapedName = CSS.escape(groupName);
    var groupCheckboxes = document.querySelectorAll('input[type="checkbox"][name="' + escapedName + '"]');

    for (var i = 0; i < groupCheckboxes.length; i++) {
      var cb = groupCheckboxes[i];
      cb.checked = isSelect;
      // Trigger change event so any "Other" / conditional logic runs
      cb.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  // ─── Initialization ────────────────────────────────────────────

  // Run on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCheckboxBulkControls);
  } else {
    initCheckboxBulkControls();
  }

  // Event delegation for bulk buttons
  document.addEventListener('click', handleBulkClick);

  // Expose for dynamic content re-initialization
  window.initCheckboxBulkControls = initCheckboxBulkControls;
})();
