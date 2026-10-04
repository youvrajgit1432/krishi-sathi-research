<?php
/**
 * Krishi Sathi Research — Observation Option Lists
 *
 * Centralized configuration for all checkbox/radio option arrays
 * used in observation-add.php and observation-edit.php.
 *
 * Edit options here to keep both forms in sync.
 * Last updated: Phase 4 — Centralized Option Lists
 */

return [

    // ─── Observed Smartphone Usage ───────────────────────────────
    'smartphone_options' => [
        'Uses smartphone during interview',
        'Has smartphone but does not use',
        'Shows interest in smartphone features',
        'No smartphone visible',
        'Other',
    ],

    // ─── Observed Record Books ───────────────────────────────────
    'record_options' => [
        'Maintains written farm records',
        'Shows records during interview',
        'Records appear organized',
        'Records appear disorganized',
        'No records visible',
        'Other',
    ],

    // ─── Observed Technology ─────────────────────────────────────
    'tech_options' => [
        'Smartphone',
        'Feature phone',
        'Internet access',
        'Radio',
        'Television',
        'Computer',
        'Other',
    ],

    // ─── Farm Condition (radio) ──────────────────────────────────
    'farm_condition_options' => [
        'Well-maintained',
        'Moderate',
        'Poor',
    ],

    // ─── Farm Condition Details (checkboxes) ─────────────────────
    'farm_condition_details' => [
        'Has irrigation system',
        'Has livestock shelter',
        'Has storage facility',
        'Uses machinery',
        'Other',
    ],
];
