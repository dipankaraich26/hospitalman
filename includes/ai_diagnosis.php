<?php
/**
 * AI-Assisted Diagnosis Engine
 * Uses symptom-disease correlation, Bayesian probability, and pattern matching
 */

/**
 * Analyze symptoms and suggest possible diseases
 *
 * @param PDO $pdo Database connection
 * @param array $symptomIds Array of symptom IDs
 * @param array $vitalSigns Optional vital signs data
 * @param int $patientId Optional patient ID for history analysis
 * @return array Suggested diseases with confidence scores
 */
function diagnoseFromSymptoms(PDO $pdo, array $symptomIds, array $vitalSigns = [], int $patientId = null): array {
    if (empty($symptomIds)) {
        return [];
    }

    // Get all diseases that match ANY of the symptoms
    $placeholders = str_repeat('?,', count($symptomIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.name,
            d.icd_code,
            d.category,
            d.severity,
            d.description,
            d.prevalence,
            COUNT(DISTINCT ds.symptom_id) as matched_symptoms,
            AVG(ds.probability) as avg_probability,
            SUM(CASE WHEN ds.is_primary = 1 THEN 1 ELSE 0 END) as primary_matches
        FROM diseases d
        INNER JOIN disease_symptoms ds ON d.id = ds.disease_id
        WHERE ds.symptom_id IN ($placeholders)
        AND d.is_active = 1
        GROUP BY d.id
        HAVING matched_symptoms > 0
        ORDER BY matched_symptoms DESC, avg_probability DESC, primary_matches DESC
        LIMIT 10
    ");
    $stmt->execute($symptomIds);
    $diseases = $stmt->fetchAll();

    // Calculate confidence scores using Bayesian approach
    $totalSymptoms = count($symptomIds);
    $results = [];

    foreach ($diseases as $disease) {
        // Base confidence from symptom match percentage
        $matchPercentage = ($disease['matched_symptoms'] / $totalSymptoms) * 100;

        // Weight by probability and primary symptoms
        $probabilityWeight = $disease['avg_probability'] / 100;
        $primaryWeight = $disease['primary_matches'] > 0 ? 1.2 : 1.0;

        // Prevalence adjustment (more common diseases get slight boost)
        $prevalenceWeight = 1 + ($disease['prevalence'] / 100);

        // Calculate final confidence score
        $confidence = min(100, (
            ($matchPercentage * 0.5) +
            ($disease['avg_probability'] * 0.3) +
            ($disease['primary_matches'] * 10 * 0.2)
        ) * $primaryWeight * $prevalenceWeight);

        $confidence = round($confidence, 2);

        // Get all symptoms for this disease
        $symptomStmt = $pdo->prepare("
            SELECT s.name, ds.probability, ds.is_primary
            FROM symptoms s
            INNER JOIN disease_symptoms ds ON s.id = ds.symptom_id
            WHERE ds.disease_id = ?
            ORDER BY ds.probability DESC, ds.is_primary DESC
        ");
        $symptomStmt->execute([$disease['id']]);
        $allSymptoms = $symptomStmt->fetchAll();

        // Get treatment recommendations
        $treatmentStmt = $pdo->prepare("
            SELECT treatment_type, recommendation, priority, success_rate
            FROM treatment_recommendations
            WHERE disease_id = ?
            ORDER BY
                CASE priority
                    WHEN 'immediate' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                END
            LIMIT 3
        ");
        $treatmentStmt->execute([$disease['id']]);
        $treatments = $treatmentStmt->fetchAll();

        $results[] = [
            'disease_id' => $disease['id'],
            'disease_name' => $disease['name'],
            'icd_code' => $disease['icd_code'],
            'category' => $disease['category'],
            'severity' => $disease['severity'],
            'description' => $disease['description'],
            'confidence' => $confidence,
            'matched_symptoms' => $disease['matched_symptoms'],
            'total_symptoms' => $totalSymptoms,
            'match_percentage' => round($matchPercentage, 1),
            'all_symptoms' => $allSymptoms,
            'treatments' => $treatments,
            'prevalence' => $disease['prevalence']
        ];
    }

    // Sort by confidence
    usort($results, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    // Add historical data if patient ID provided
    if ($patientId) {
        $results = enhanceWithPatientHistory($pdo, $results, $patientId);
    }

    // Add vital signs analysis
    if (!empty($vitalSigns)) {
        $results = enhanceWithVitalSigns($results, $vitalSigns);
    }

    return $results;
}

/**
 * Enhance diagnosis with patient medical history
 */
function enhanceWithPatientHistory(PDO $pdo, array $results, int $patientId): array {
    // Get patient's previous diagnoses
    $stmt = $pdo->prepare("
        SELECT doctor_diagnosis, created_at
        FROM ai_diagnosis_records
        WHERE patient_id = ?
        AND doctor_diagnosis IS NOT NULL
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$patientId]);
    $history = $stmt->fetchAll();

    // Boost confidence for recurring conditions
    foreach ($results as &$result) {
        $historicalMatches = 0;
        foreach ($history as $record) {
            if (stripos($record['doctor_diagnosis'], $result['disease_name']) !== false) {
                $historicalMatches++;
            }
        }

        if ($historicalMatches > 0) {
            $result['confidence'] = min(100, $result['confidence'] * (1 + ($historicalMatches * 0.1)));
            $result['historical_matches'] = $historicalMatches;
            $result['is_recurring'] = true;
        }
    }

    return $results;
}

/**
 * Enhance diagnosis with vital signs analysis
 */
function enhanceWithVitalSigns(array $results, array $vitalSigns): array {
    foreach ($results as &$result) {
        $vitalAlerts = [];

        // Fever indicator
        if (isset($vitalSigns['temperature']) && $vitalSigns['temperature'] > 38.0) {
            if (stripos($result['disease_name'], 'flu') !== false ||
                stripos($result['disease_name'], 'pneumonia') !== false ||
                stripos($result['disease_name'], 'covid') !== false) {
                $result['confidence'] = min(100, $result['confidence'] * 1.15);
                $vitalAlerts[] = 'Elevated temperature supports this diagnosis';
            }
        }

        // Blood pressure
        if (isset($vitalSigns['blood_pressure_systolic']) && $vitalSigns['blood_pressure_systolic'] > 140) {
            if (stripos($result['disease_name'], 'hypertension') !== false) {
                $result['confidence'] = min(100, $result['confidence'] * 1.2);
                $vitalAlerts[] = 'Elevated blood pressure confirms diagnosis';
            }
        }

        // Heart rate
        if (isset($vitalSigns['heart_rate']) && $vitalSigns['heart_rate'] > 100) {
            if (stripos($result['category'], 'cardiovascular') !== false ||
                $result['severity'] === 'severe') {
                $vitalAlerts[] = 'Elevated heart rate indicates severity';
            }
        }

        // Oxygen saturation
        if (isset($vitalSigns['oxygen_saturation']) && $vitalSigns['oxygen_saturation'] < 95) {
            if (stripos($result['category'], 'respiratory') !== false) {
                $result['confidence'] = min(100, $result['confidence'] * 1.2);
                $vitalAlerts[] = 'Low oxygen saturation supports respiratory diagnosis';
            }
        }

        if (!empty($vitalAlerts)) {
            $result['vital_alerts'] = $vitalAlerts;
        }
    }

    return $results;
}

/**
 * Check for drug interactions
 */
function checkDrugInteractions(PDO $pdo, array $drugNames): array {
    if (count($drugNames) < 2) {
        return [];
    }

    $interactions = [];

    for ($i = 0; $i < count($drugNames); $i++) {
        for ($j = $i + 1; $j < count($drugNames); $j++) {
            $stmt = $pdo->prepare("
                SELECT * FROM drug_interactions
                WHERE (drug_a = ? AND drug_b = ?)
                OR (drug_a = ? AND drug_b = ?)
            ");
            $stmt->execute([
                $drugNames[$i], $drugNames[$j],
                $drugNames[$j], $drugNames[$i]
            ]);

            if ($interaction = $stmt->fetch()) {
                $interactions[] = $interaction;
            }
        }
    }

    return $interactions;
}

/**
 * Get similar past cases
 */
function findSimilarCases(PDO $pdo, array $symptomIds, int $limit = 5): array {
    if (empty($symptomIds)) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT
            adr.*,
            p.first_name,
            p.last_name,
            TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
            p.gender,
            u.full_name as doctor_name
        FROM ai_diagnosis_records adr
        INNER JOIN patients p ON adr.patient_id = p.id
        INNER JOIN users u ON adr.doctor_id = u.id
        WHERE adr.doctor_diagnosis IS NOT NULL
        ORDER BY adr.created_at DESC
        LIMIT 100
    ");
    $cases = $stmt->fetchAll();

    $similarCases = [];

    foreach ($cases as $case) {
        $caseSymptoms = json_decode($case['symptoms_input'], true);
        if (!$caseSymptoms) continue;

        // Calculate symptom match percentage
        $intersection = count(array_intersect($symptomIds, $caseSymptoms));
        $union = count(array_unique(array_merge($symptomIds, $caseSymptoms)));
        $similarity = $union > 0 ? ($intersection / $union) * 100 : 0;

        if ($similarity > 30) { // At least 30% similar
            $similarCases[] = [
                'patient_age' => $case['age'],
                'patient_gender' => $case['gender'],
                'diagnosis' => $case['doctor_diagnosis'],
                'doctor_name' => $case['doctor_name'],
                'similarity' => round($similarity, 1),
                'date' => $case['created_at'],
                'was_accurate' => $case['was_accurate']
            ];
        }
    }

    // Sort by similarity
    usort($similarCases, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    return array_slice($similarCases, 0, $limit);
}

/**
 * Save diagnosis record
 */
function saveDiagnosisRecord(
    PDO $pdo,
    int $patientId,
    int $doctorId,
    array $symptomIds,
    array $vitalSigns,
    array $aiSuggestions,
    ?string $doctorDiagnosis = null
): int {
    $stmt = $pdo->prepare("
        INSERT INTO ai_diagnosis_records
        (patient_id, doctor_id, symptoms_input, vital_signs, ai_suggestions, doctor_diagnosis, confidence_score)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $topConfidence = !empty($aiSuggestions) ? $aiSuggestions[0]['confidence'] : null;

    $stmt->execute([
        $patientId,
        $doctorId,
        json_encode($symptomIds),
        json_encode($vitalSigns),
        json_encode($aiSuggestions),
        $doctorDiagnosis,
        $topConfidence
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Get AI diagnosis accuracy statistics
 */
function getAIAccuracyStats(PDO $pdo, int $days = 30): array {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_diagnoses,
            SUM(CASE WHEN was_accurate = 1 THEN 1 ELSE 0 END) as accurate,
            SUM(CASE WHEN was_accurate = 0 THEN 1 ELSE 0 END) as inaccurate,
            AVG(confidence_score) as avg_confidence
        FROM ai_diagnosis_records
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
        AND was_accurate IS NOT NULL
    ");

    $stats = $stmt->fetch();

    if ($stats['total_diagnoses'] > 0) {
        $stats['accuracy_rate'] = round(($stats['accurate'] / $stats['total_diagnoses']) * 100, 2);
    } else {
        $stats['accuracy_rate'] = 0;
    }

    $stats['avg_confidence'] = round($stats['avg_confidence'], 2);

    return $stats;
}
