<?php
/**
 * Krishi Sathi Research — Interview Option Lists
 *
 * Centralized configuration for all checkbox/radio option arrays
 * used in interview-add.php and interview-edit.php.
 *
 * Edit options here to keep both forms in sync.
 * Last updated: Phase 3 — Centralized Option Lists
 */

/**
 * Get default selections for farmer interviews.
 * Pre-selects the most commonly observed answers from field research (27 interviews analyzed).
 * Only applies to NEW interviews (old() returns null on fresh GET).
 * Researchers retain full discretion to change any selection.
 */
function getInterviewDefaults(): array {
    return [
        'farm_type' => ['Crop', 'Livestock'],
        'problems' => ['Disease/Pest Problems', 'Market Price Issues', 'Record Keeping Challenges', 'Weather Problems', 'Lack of Technical Knowledge'],
        'loss_causes' => ['Disease', 'Weather', 'Market', 'Technical Knowledge Gap'],
        'record_keeping' => ['Notebook'],
        'technology' => ['Smartphone', 'Internet', 'Facebook', 'YouTube'],
        'voice_interest' => 'yes',
        'voice_reason_options' => ['Easier than typing', 'Saves time', 'Helps maintain records', 'Can use while working'],
        'voice_rejection_reasons' => [],
        'voice_use_cases' => ['Activity Recording', 'Expense Recording', 'Reminder Management'],
        'assumption_reminders' => 'yes',
        'reminder_types' => ['Fertilizer Application', 'Pesticide Follow-up', 'Harvesting', 'Irrigation', 'Vaccination'],
        'app_motivations' => ['Easier Record Keeping', 'Prevent Forgetting', 'Better Planning', 'Alerts and Reminders'],
        'recommendation_types' => ['Disease Prevention', 'Weather-based Advice', 'Fertilizer Timing', 'Irrigation Timing', 'Vaccination Scheduling'],
    ];
}

return [

    // ─── Farm Types ──────────────────────────────────────────────
    'farm_types' => [
        'Crop',
        'Livestock',
        'Poultry',
        'Mixed',
    ],

    // ─── Problems ────────────────────────────────────────────────
    'problems' => [
        'Disease/Pest Problems',
        'Weather Problems',
        'Market Price Issues',
        'Labor Shortage',
        'High Input Costs',
        'Irrigation Problems',
        'Lack of Technical Knowledge',
        'Record Keeping Challenges',
        'Financial Constraints',
        'Input Availability Issues',
        'Animal Health Problems',
    ],

    // ─── Loss Contributing Causes ────────────────────────────────
    'loss_causes' => [
        'Disease',
        'Weather',
        'Market',
        'Labor',
        'Finance',
        'Input Costs',
        'Animal Health',
        'Irrigation Problems',
        'Technical Knowledge Gap',
        'Other',
    ],

    // ─── Voice Reasons (when Yes/Maybe) ──────────────────────────
    'voice_reasons' => [
        'Easier than typing',
        'Saves time',
        'Helps maintain records',
        'Can use while working',
        'Prevents forgetting activities',
        'Useful for reminders',
        'Interested in technology',
        'More convenient',
        'Other',
    ],

    // ─── Voice Rejection Reasons (when No) ───────────────────────
    'voice_rejections' => [
        'Prefer manual methods',
        'Do not trust technology',
        'Difficult to use smartphone',
        'Privacy concerns',
        'Prefer family assistance',
        'Not interested',
        'Other',
    ],

    // ─── Voice Use Cases ─────────────────────────────────────────
    'voice_use_cases' => [
        'Activity Recording',
        'Expense Recording',
        'Harvest Recording',
        'Reminder Management',
        'Weather Queries',
        'Recommendation Queries',
        'Animal Health Records',
        'Market Information',
        'Farm Planning',
        'Other',
    ],

    // ─── Reminder Types ──────────────────────────────────────────
    'reminder_types' => [
        'Fertilizer Application',
        'Pesticide Follow-up',
        'Irrigation',
        'Harvesting',
        'Vaccination',
        'Artificial Insemination',
        'Market Activities',
        'Weather Alerts',
        'Other',
    ],

    // ─── App Motivation Options ──────────────────────────────────
    'app_motivations' => [
        'Easier Record Keeping',
        'Prevent Forgetting',
        'Better Planning',
        'Expense Tracking',
        'Income Tracking',
        'Recommendations',
        'Alerts and Reminders',
        'Family Coordination',
        'Expert Advice',
        'Other',
    ],

    // ─── Recommendation Types ────────────────────────────────────
    'recommendations' => [
        'Disease Prevention',
        'Weather-based Advice',
        'Fertilizer Timing',
        'Irrigation Timing',
        'Vaccination Scheduling',
        'Harvest Timing',
        'Market Recommendations',
        'Input Recommendations',
        'Animal Health Advice',
        'Other',
    ],

    // ─── Record Keeping Options ──────────────────────────────────
    'record_options' => [
        'Notebook',
        'Memory',
        'Excel',
        'Mobile App',
        'No Records',
    ],

    // ─── Record Frequencies ──────────────────────────────────────
    'record_frequencies' => [
        'Daily',
        'Weekly',
        'Monthly',
        'Occasionally',
        'Never',
    ],

    // ─── Technology Options ──────────────────────────────────────
    'tech_options' => [
        'Smartphone',
        'Internet',
        'Facebook',
        'YouTube',
        'TikTok',
        'Agriculture Apps',
    ],

    // ─── Smartphone Independence Options ─────────────────────────
    'smartphone_options' => [
        'Yes',
        'Sometimes',
        'Needs Assistance',
    ],
];
