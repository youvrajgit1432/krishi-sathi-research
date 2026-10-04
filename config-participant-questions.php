<?php
/**
 * Krishi Sathi Research System — Stakeholder Interview Question Sets
 *
 * All question options organized by interview section.
 * Follows the 80% checkbox / 15% radio / 5% free text design philosophy.
 *
 * Every function returns an array of option strings.
 * "Other" is included inline where applicable.
 */

// ═══════════════════════════════════════════════════════════════════
// SECTION 2 — FARMER PROBLEMS OBSERVED
// ═══════════════════════════════════════════════════════════════════

/**
 * What problems do farmers face most often?
 * Multi-select checkboxes.
 */
function getProblemsObservedOptions(): array {
    return [
        'Disease',
        'Pest Attack',
        'Weather Damage',
        'Water Shortage',
        'Irrigation Problems',
        'Poor Seed Quality',
        'Fertilizer Issues',
        'Animal Disease',
        'Lack of Knowledge',
        'Lack of Skilled Labor',
        'Market Problems',
        'Transportation Problems',
        'Financial Problems',
        'Record Keeping Problems',
        'Technology Adoption Problems',
        'Other',
    ];
}

/**
 * Which problems are increasing?
 * Multi-select checkboxes.
 */
function getProblemsIncreasingOptions(): array {
    return [
        'Disease',
        'Pest Attack',
        'Climate Change',
        'Water Issues',
        'Input Costs',
        'Labor Shortage',
        'Market Instability',
        'Livestock Disease',
        'Other',
    ];
}

/**
 * Which problems cause the greatest losses?
 * Multi-select checkboxes.
 */
function getProblemsGreatestLossesOptions(): array {
    return [
        'Disease',
        'Weather',
        'Wrong Decisions',
        'Poor Inputs',
        'Animal Health Problems',
        'Market Price Drops',
        'Transportation Issues',
        'Labor Shortage',
        'Other',
    ];
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 3 — DECISION MAKING
// ═══════════════════════════════════════════════════════════════════

/**
 * What decisions are hardest for farmers?
 * Multi-select checkboxes.
 */
function getHardestDecisionsOptions(): array {
    return [
        'Crop Selection',
        'Variety Selection',
        'Fertilizer Timing',
        'Irrigation Timing',
        'Disease Treatment',
        'Pesticide Selection',
        'Harvest Timing',
        'Selling Timing',
        'Livestock Feeding',
        'Livestock Vaccination',
        'Breeding Decisions',
        'Expansion Decisions',
        'Other',
    ];
}

/**
 * Why are these decisions difficult?
 * Multi-select checkboxes.
 */
function getDecisionDifficultyOptions(): array {
    return [
        'Lack of Information',
        'Information Arrives Too Late',
        'Too Many Conflicting Sources',
        'Lack of Trust',
        'Financial Risk',
        'Weather Uncertainty',
        'Market Uncertainty',
        'Other',
    ];
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 4 — TRUST & INFORMATION
// ═══════════════════════════════════════════════════════════════════

/**
 * Where do farmers get information?
 * Multi-select checkboxes.
 */
function getInfoSourcesOptions(): array {
    return [
        'Agrovet',
        'Neighbor',
        'Family',
        'Cooperative',
        'Municipality Office',
        'Agriculture Technician',
        'YouTube',
        'Facebook',
        'TikTok',
        'Radio',
        'Television',
        'Newspapers',
        'Government Programs',
        'Other',
    ];
}

/**
 * Which sources do farmers trust most?
 * Multi-select checkboxes.
 */
function getTrustedSourcesOptions(): array {
    return [
        'Agrovet',
        'Neighbor',
        'Cooperative',
        'Municipality',
        'Agriculture Technician',
        'YouTube',
        'Facebook',
        'Radio',
        'Government Programs',
        'Other',
    ];
}

/**
 * What information arrives too late?
 * Multi-select checkboxes.
 */
function getLateInformationOptions(): array {
    return [
        'Weather Alerts',
        'Disease Alerts',
        'Pest Alerts',
        'Fertilizer Recommendations',
        'Irrigation Advice',
        'Market Prices',
        'Government Programs',
        'Training Information',
        'Livestock Disease Alerts',
        'Vaccination Alerts',
        'Other',
    ];
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 5 — RECORD KEEPING
// ═══════════════════════════════════════════════════════════════════

/**
 * How do farmers usually keep records?
 * Multi-select checkboxes.
 */
function getRecordKeepingMethodsOptions(): array {
    return [
        'Memory Only',
        'Notebook',
        'Register Book',
        'Excel',
        'Mobile Notes',
        'Mobile Apps',
        'No Records',
        'Other',
    ];
}

/**
 * Which records are most important?
 * Multi-select checkboxes.
 */
function getImportantRecordsOptions(): array {
    return [
        'Expenses',
        'Income',
        'Production',
        'Fertilizer Use',
        'Pesticide Use',
        'Disease Records',
        'Harvest Records',
        'Animal Health Records',
        'Vaccination Records',
        'Breeding Records',
        'Other',
    ];
}

/**
 * What prevents proper record keeping?
 * Multi-select checkboxes.
 */
function getRecordKeepingBarriersOptions(): array {
    return [
        'Lack of Time',
        'Difficult Process',
        'Lack of Awareness',
        'Low Education',
        'No Useful Tools',
        'No Immediate Benefit',
        'Other',
    ];
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 6 — TECHNOLOGY ADOPTION
// ═══════════════════════════════════════════════════════════════════

/**
 * What technologies are farmers already using?
 * Multi-select checkboxes.
 */
function getTechnologiesUsedOptions(): array {
    return [
        'Smartphone',
        'Facebook',
        'YouTube',
        'TikTok',
        'Mobile Banking',
        'eSewa',
        'Weather Apps',
        'Agriculture Apps',
        'GPS',
        'Digital Payments',
        'Other',
    ];
}

/**
 * What prevents technology adoption?
 * Multi-select checkboxes.
 */
function getTechAdoptionBarriersOptions(): array {
    return [
        'Cost',
        'Lack of Training',
        'Poor Internet',
        'Lack of Trust',
        'Complexity',
        'Language Barriers',
        'Age',
        'Other',
    ];
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 7 — VOICE VALIDATION
// ═══════════════════════════════════════════════════════════════════

/**
 * What should voice recording be used for?
 * Multi-select checkboxes. (Shown if voice recording is useful)
 */
function getVoiceRecordingUsesOptions(): array {
    return [
        'Expense Recording',
        'Income Recording',
        'Activity Recording',
        'Harvest Recording',
        'Animal Records',
        'Vaccination Records',
        'Notes',
        'Questions',
        'Other',
    ];
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 8 — KRISHI SATHI VALIDATION
// ═══════════════════════════════════════════════════════════════════

/**
 * Which reminders would be most useful?
 * Multi-select checkboxes. (Shown if reminders are valuable)
 */
function getUsefulRemindersOptions(): array {
    return [
        'Fertilizer',
        'Irrigation',
        'Disease Monitoring',
        'Harvest',
        'Market Selling',
        'Vaccination',
        'Government Programs',
        'Training',
        'Other',
    ];
}

/**
 * Which recommendations are most valuable?
 * Multi-select checkboxes. (Shown if weekly recommendations are useful)
 */
function getValuableRecommendationsOptions(): array {
    return [
        'Disease Prevention',
        'Weather Advice',
        'Fertilizer Advice',
        'Irrigation Advice',
        'Market Advice',
        'Livestock Advice',
        'Vaccination Advice',
        'Harvest Advice',
        'Other',
    ];
}

/**
 * Most Valuable Future Feature.
 * Multi-select checkboxes.
 */
function getMostValuableFeatureOptions(): array {
    return [
        'Voice Assistant',
        'Disease Alerts',
        'Weather Alerts',
        'Market Alerts',
        'Farm Records',
        'Livestock Records',
        'Government Programs',
        'Subsidy Alerts',
        'Training Content',
        'Community Discussion',
        'Expert Consultation',
        'Other',
    ];
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 9 — ROLE-SPECIFIC QUESTIONS
// ═══════════════════════════════════════════════════════════════════

// Each participant type gets its own question set.
// The interview form shows ONLY the set matching the participant's type.

/**
 * Get role-specific questions for a given participant type.
 * Returns an array of question_key => question_data mappings.
 *
 * Each question_data has:
 *   'label'     => string (the question text)
 *   'type'      => 'checkbox' | 'radio' | 'text' | 'textarea'
 *   'options'   => array (for checkbox/radio)
 *   'key'       => string (DB column key for research_participant_responses)
 */
function getRoleSpecificQuestions(string $participantType): array {
    $questions = [];

    switch ($participantType) {

        // ─── AGROVET OWNER ────────────────────────────────────
        case 'Agrovet Owner':
            $questions[] = [
                'key'     => 'agrovet_products_sold',
                'label'   => 'What products do you sell most?',
                'type'    => 'checkbox',
                'options' => [
                    'Seeds', 'Fertilizers', 'Pesticides', 'Animal Feed',
                    'Veterinary Medicines', 'Tools', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'agrovet_top_selling',
                'label'   => 'What are your top 3 selling products?',
                'type'    => 'text',
                'options' => [],
            ];
            $questions[] = [
                'key'     => 'agrovet_services',
                'label'   => 'What services do you provide?',
                'type'    => 'checkbox',
                'options' => [
                    'Product Sales', 'Technical Advice', 'Disease Diagnosis',
                    'Vaccination', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'agrovet_farmer_needs',
                'label'   => 'What do farmers ask you most about?',
                'type'    => 'checkbox',
                'options' => [
                    'Disease Treatment', 'Pest Control', 'Fertilizer Advice',
                    'Seed Selection', 'Market Information', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'agrovet_challenges',
                'label'   => 'What challenges do you face serving farmers?',
                'type'    => 'checkbox',
                'options' => [
                    'Supply Chain Issues', 'Lack of Information',
                    'Farmer Trust', 'Payment Issues', 'Other',
                ],
            ];
            break;

        // ─── AGRICULTURE TECHNICIAN ────────────────────────────
        case 'Agriculture Technician':
            $questions[] = [
                'key'     => 'tech_services_provided',
                'label'   => 'What services do you provide to farmers?',
                'type'    => 'checkbox',
                'options' => [
                    'Crop Advice', 'Disease Diagnosis', 'Pest Management',
                    'Soil Testing', 'Training', 'Demonstration',
                    'Input Recommendations', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'tech_farmers_served',
                'label'   => 'How many farmers do you typically serve?',
                'type'    => 'radio',
                'options' => [
                    'Less than 50', '50-100', '100-200', '200-500', 'More than 500',
                ],
            ];
            $questions[] = [
                'key'     => 'tech_common_requests',
                'label'   => 'What are the most common requests?',
                'type'    => 'checkbox',
                'options' => [
                    'Disease Treatment', 'Pest Control', 'Fertilizer Advice',
                    'Irrigation Advice', 'Market Information', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'tech_coordination',
                'label'   => 'How do you coordinate with other agricultural service providers?',
                'type'    => 'checkbox',
                'options' => [
                    'Municipality Meetings', 'Cooperative Networks', 'Phone',
                    'Social Media', 'No Coordination', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'tech_challenges',
                'label'   => 'What challenges do you face?',
                'type'    => 'checkbox',
                'options' => [
                    'Limited Resources', 'Transportation', 'Lack of Training',
                    'Farmer Reach', 'Administrative Burden', 'Other',
                ],
            ];
            break;

        // ─── MUNICIPALITY AGRICULTURE OFFICER ──────────────────
        case 'Municipality Agriculture Officer':
            $questions[] = [
                'key'     => 'mun_officer_role',
                'label'   => 'What is your specific role in agricultural development?',
                'type'    => 'textarea',
                'options' => [],
            ];
            $questions[] = [
                'key'     => 'mun_officer_jurisdiction',
                'label'   => 'What is your jurisdiction area?',
                'type'    => 'radio',
                'options' => [
                    'Single Ward', 'Multiple Wards', 'Entire Municipality', 'District Level',
                ],
            ];
            $questions[] = [
                'key'     => 'mun_officer_programs',
                'label'   => 'What agricultural programs does your municipality run?',
                'type'    => 'checkbox',
                'options' => [
                    'Subsidies', 'Training Programs', 'Seed Distribution',
                    'Livestock Programs', 'Irrigation Projects', 'Market Development', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'mun_officer_coordination',
                'label'   => 'How do you coordinate with provincial/federal agriculture programs?',
                'type'    => 'checkbox',
                'options' => [
                    'Regular Meetings', 'Reporting System', 'Joint Programs',
                    'Limited Coordination', 'No Coordination', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'mun_officer_priorities',
                'label'   => 'What are the priority areas for agriculture in your municipality?',
                'type'    => 'textarea',
                'options' => [],
            ];
            break;

        // ─── COOPERATIVE LEADER ────────────────────────────────
        case 'Cooperative Leader':
            $questions[] = [
                'key'     => 'coop_type',
                'label'   => 'What type of cooperative do you lead?',
                'type'    => 'checkbox',
                'options' => [
                    'Agricultural', 'Dairy', 'Saving & Credit',
                    'Multi-purpose', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'coop_members',
                'label'   => 'How many members does your cooperative have?',
                'type'    => 'radio',
                'options' => [
                    'Less than 50', '50-100', '100-200', '200-500', 'More than 500',
                ],
            ];
            $questions[] = [
                'key'     => 'coop_services',
                'label'   => 'What services do you provide to members?',
                'type'    => 'checkbox',
                'options' => [
                    'Credit', 'Input Supply', 'Market Access', 'Training',
                    'Storage', 'Insurance', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'coop_challenges',
                'label'   => 'What are your biggest challenges?',
                'type'    => 'checkbox',
                'options' => [
                    'Member Retention', 'Financial Management', 'Market Linkages',
                    'Technology Adoption', 'Governance', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'coop_needs',
                'label'   => 'What does your cooperative need most?',
                'type'    => 'checkbox',
                'options' => [
                    'Digital Tools', 'Training', 'Market Information',
                    'Infrastructure', 'Technical Support', 'Other',
                ],
            ];
            break;

        // ─── VETERINARIAN ──────────────────────────────────────
        case 'Veterinarian':
            $questions[] = [
                'key'     => 'vet_services',
                'label'   => 'What veterinary services do you provide?',
                'type'    => 'checkbox',
                'options' => [
                    'Treatment', 'Vaccination', 'Surgery', 'Artificial Insemination',
                    'Disease Diagnosis', 'Consultation', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'vet_livestock_types',
                'label'   => 'Which livestock types do you serve most?',
                'type'    => 'checkbox',
                'options' => [
                    'Cattle', 'Buffalo', 'Goats', 'Sheep', 'Poultry',
                    'Pigs', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'vet_common_diseases',
                'label'   => 'What are the most common diseases you encounter?',
                'type'    => 'textarea',
                'options' => [],
            ];
            $questions[] = [
                'key'     => 'vet_challenges',
                'label'   => 'What challenges do you face?',
                'type'    => 'checkbox',
                'options' => [
                    'Medicine Availability', 'Farmer Awareness',
                    'Transportation', 'Cost of Treatment', 'Lab Facilities', 'Other',
                ],
            ];
            break;

        // ─── DAIRY FARMER ──────────────────────────────────────
        case 'Dairy Farmer':
            $questions[] = [
                'key'     => 'dairy_herd_size',
                'label'   => 'How many dairy animals do you have?',
                'type'    => 'radio',
                'options' => [
                    '1-2', '3-5', '6-10', '11-20', 'More than 20',
                ],
            ];
            $questions[] = [
                'key'     => 'dairy_production',
                'label'   => 'What is your daily milk production?',
                'type'    => 'radio',
                'options' => [
                    'Less than 5L', '5-10L', '10-20L', '20-50L', 'More than 50L',
                ],
            ];
            $questions[] = [
                'key'     => 'dairy_feeding',
                'label'   => 'What do you feed your animals?',
                'type'    => 'checkbox',
                'options' => [
                    'Green Fodder', 'Dry Fodder', 'Concentrate Feed',
                    'Mineral Mixture', 'Feed Supplements', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'dairy_challenges',
                'label'   => 'What challenges do you face?',
                'type'    => 'checkbox',
                'options' => [
                    'Feed Cost', 'Disease', 'Milk Price', 'Marketing',
                    'Veterinary Access', 'Labor', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'dairy_needs',
                'label'   => 'What would help improve your dairy business?',
                'type'    => 'checkbox',
                'options' => [
                    'Better Feed', 'Health Monitoring', 'Market Access',
                    'Training', 'Financial Services', 'Other',
                ],
            ];
            break;

        // ─── POULTRY FARMER ────────────────────────────────────
        case 'Poultry Farmer':
            $questions[] = [
                'key'     => 'poultry_flock_size',
                'label'   => 'What is your flock size?',
                'type'    => 'radio',
                'options' => [
                    'Less than 50', '50-200', '200-500', '500-1000', 'More than 1000',
                ],
            ];
            $questions[] = [
                'key'     => 'poultry_type',
                'label'   => 'What type of poultry do you raise?',
                'type'    => 'checkbox',
                'options' => [
                    'Broilers', 'Layers', 'Dual Purpose', 'Native Breed', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'poultry_challenges',
                'label'   => 'What challenges do you face?',
                'type'    => 'checkbox',
                'options' => [
                    'Disease', 'Feed Cost', 'Market Price', 'Mortality',
                    'Biosecurity', 'Chick Quality', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'poultry_needs',
                'label'   => 'What would help improve your poultry farm?',
                'type'    => 'checkbox',
                'options' => [
                    'Disease Alerts', 'Vaccination Reminders',
                    'Market Information', 'Feed Management', 'Training', 'Other',
                ],
            ];
            break;

        // ─── GOAT FARMER ───────────────────────────────────────
        case 'Goat Farmer':
            $questions[] = [
                'key'     => 'goat_herd_size',
                'label'   => 'How many goats do you have?',
                'type'    => 'radio',
                'options' => [
                    '1-5', '6-10', '11-20', '21-50', 'More than 50',
                ],
            ];
            $questions[] = [
                'key'     => 'goat_breeds',
                'label'   => 'What breeds do you raise?',
                'type'    => 'checkbox',
                'options' => [
                    'Local', 'Jamunapari', 'Boer', 'Barbari', 'Mixed', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'goat_challenges',
                'label'   => 'What challenges do you face?',
                'type'    => 'checkbox',
                'options' => [
                    'Disease', 'Feed Scarcity', 'Predators', 'Market Price',
                    'Veterinary Access', 'Breeding', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'goat_needs',
                'label'   => 'What would help improve your goat farming?',
                'type'    => 'checkbox',
                'options' => [
                    'Health Alerts', 'Vaccination Reminders',
                    'Market Information', 'Breeding Support', 'Training', 'Other',
                ],
            ];
            break;

        // ─── TRADER ────────────────────────────────────────────
        case 'Trader':
            $questions[] = [
                'key'     => 'trader_products',
                'label'   => 'What agricultural products do you trade?',
                'type'    => 'checkbox',
                'options' => [
                    'Grains', 'Vegetables', 'Fruits', 'Livestock',
                    'Dairy Products', 'Inputs', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'trader_volume',
                'label'   => 'What is your approximate monthly trading volume?',
                'type'    => 'radio',
                'options' => [
                    'Less than NPR 50,000', 'NPR 50,000-200,000',
                    'NPR 200,000-500,000', 'NPR 500,000-1,000,000', 'More than NPR 1,000,000',
                ],
            ];
            $questions[] = [
                'key'     => 'trader_sources',
                'label'   => 'Where do you source your products?',
                'type'    => 'checkbox',
                'options' => [
                    'Local Farmers', 'Cooperatives', 'Wholesale Markets',
                    'Direct from Farms', 'Other Districts', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'trader_challenges',
                'label'   => 'What challenges do you face?',
                'type'    => 'checkbox',
                'options' => [
                    'Price Fluctuation', 'Transportation', 'Storage',
                    'Quality Control', 'Market Information', 'Credit', 'Other',
                ],
            ];
            break;

        // ─── COLLECTION CENTER ─────────────────────────────────
        case 'Collection Center':
            $questions[] = [
                'key'     => 'collection_products',
                'label'   => 'What products do you collect?',
                'type'    => 'checkbox',
                'options' => [
                    'Milk', 'Vegetables', 'Fruits', 'Grains',
                    'Livestock', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'collection_capacity',
                'label'   => 'What is your daily collection capacity?',
                'type'    => 'radio',
                'options' => [
                    'Less than 100L/kg', '100-500L/kg',
                    '500-1000L/kg', 'More than 1000L/kg',
                ],
            ];
            $questions[] = [
                'key'     => 'collection_suppliers',
                'label'   => 'Who supplies to your collection center?',
                'type'    => 'checkbox',
                'options' => [
                    'Individual Farmers', 'Cooperatives', 'Local Traders', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'collection_challenges',
                'label'   => 'What challenges do you face?',
                'type'    => 'checkbox',
                'options' => [
                    'Quality Consistency', 'Transportation', 'Storage',
                    'Price Negotiation', 'Payment Collection', 'Other',
                ],
            ];
            break;

        // ─── RESEARCHER ────────────────────────────────────────
        case 'Researcher':
            $questions[] = [
                'key'     => 'researcher_focus',
                'label'   => 'What is your main research focus area?',
                'type'    => 'textarea',
                'options' => [],
            ];
            $questions[] = [
                'key'     => 'researcher_methods',
                'label'   => 'What research methods do you use?',
                'type'    => 'checkbox',
                'options' => [
                    'Surveys', 'Interviews', 'Field Trials', 'Lab Analysis',
                    'Secondary Data Analysis', 'Participatory Research', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'researcher_collaboration',
                'label'   => 'Who do you collaborate with?',
                'type'    => 'checkbox',
                'options' => [
                    'Government', 'NGOs', 'Universities', 'Cooperatives',
                    'Private Sector', 'Farmers', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'researcher_challenges',
                'label'   => 'What challenges do you face in agricultural research?',
                'type'    => 'checkbox',
                'options' => [
                    'Funding', 'Data Access', 'Farmer Participation',
                    'Field Access', 'Publication', 'Technology', 'Other',
                ],
            ];
            break;

        // ─── OTHER (default) ───────────────────────────────────
        default:
            $questions[] = [
                'key'     => 'other_role_description',
                'label'   => 'Please describe your role in agriculture:',
                'type'    => 'textarea',
                'options' => [],
            ];
            $questions[] = [
                'key'     => 'other_activities',
                'label'   => 'What agricultural activities are you involved in?',
                'type'    => 'checkbox',
                'options' => [
                    'Production', 'Processing', 'Marketing', 'Research',
                    'Extension', 'Policy', 'Education', 'Other',
                ],
            ];
            $questions[] = [
                'key'     => 'other_challenges',
                'label'   => 'What challenges do you observe in the agricultural sector?',
                'type'    => 'textarea',
                'options' => [],
            ];
            break;
    }

    return $questions;
}


// ═══════════════════════════════════════════════════════════════════
// SECTION 10 — RESEARCHER FINDINGS
// ═══════════════════════════════════════════════════════════════════

/**
 * Options for the "recommended action" checkboxes in researcher findings.
 * (Also defined in config-participants.php)
 */
// (Uses getRecommendedActionOptions() from config-participants.php)


// ═══════════════════════════════════════════════════════════════════
// HELPER: Get all option sets grouped by interview step
// ═══════════════════════════════════════════════════════════════════

/**
 * Get the label and fields for each of the 10 interview steps.
 * Used by the interview wizard to render progressive steps.
 *
 * Returns: [ [ 'label' => 'Step Name', 'fields' => [...] ], ... ]
 */
/**
 * Get default selections for stakeholder (participant) interviews.
 * Pre-selects the most commonly observed answers from field research (15 interviews analyzed).
 * Only applies to NEW interviews (old() returns null on fresh GET).
 * Researchers retain full discretion to change any selection.
 */
function getParticipantInterviewDefaults(): array {
    return [
        'problems_observed' => ['Disease', 'Pest Attack', 'Weather Damage', 'Market Problems', 'Lack of Knowledge'],
        'problems_increasing' => ['Disease', 'Climate Change', 'Market Instability', 'Input Costs', 'Water Issues'],
        'problems_greatest_losses' => ['Disease', 'Weather', 'Market Price Drops', 'Animal Health Problems'],
        'hardest_decisions' => [],
        'decision_difficulties' => [],
        'info_sources' => ['Agrovet', 'Cooperative', 'Agriculture Technician'],
        'trusted_sources' => ['Agrovet', 'Cooperative', 'Agriculture Technician'],
        'late_info' => ['Weather Alerts', 'Disease Alerts', 'Market Prices'],
        'record_methods' => ['Notebook'],
        'important_records' => ['Expenses', 'Income', 'Production'],
        'record_barriers' => ['Lack of Time', 'Difficult Process', 'No Useful Tools'],
        'tech_used' => ['Smartphone', 'Facebook', 'YouTube'],
        'tech_barriers' => ['Cost', 'Lack of Training', 'Poor Internet'],
        'voice_recording_useful' => 'yes',
        'voice_recording_uses' => ['Expense Recording', 'Activity Recording', 'Notes'],
        'reminders_valuable' => 'yes',
        'useful_reminders' => ['Fertilizer', 'Irrigation', 'Vaccination', 'Harvest', 'Disease Monitoring'],
        'weekly_recs_useful' => 'yes',
        'valuable_recommendations' => ['Disease Prevention', 'Weather Advice', 'Fertilizer Advice', 'Market Advice'],
        'most_valuable_feature' => ['Voice Assistant', 'Disease Alerts', 'Weather Alerts'],
        'recommended_action' => ['Conduct Detailed Study', 'Develop Mobile Solution', 'Create Awareness Program'],
    ];
}

function getInterviewStepDefinitions(): array {
    return [
        ['label' => 'Participant Info',       'section' => 'profile'],
        ['label' => 'Problems Observed',      'section' => 'problems'],
        ['label' => 'Problems Detail',        'section' => 'problems_detail'],
        ['label' => 'Decision Making',        'section' => 'decisions'],
        ['label' => 'Trust & Information',    'section' => 'information'],
        ['label' => 'Record Keeping',         'section' => 'records'],
        ['label' => 'Technology Adoption',    'section' => 'technology'],
        ['label' => 'Voice & Validation',     'section' => 'validation'],
        ['label' => 'Role-Specific',          'section' => 'role_specific'],
        ['label' => 'Research Findings',      'section' => 'findings'],
    ];
}
