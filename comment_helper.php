<?php
// file: comment_helper.php
// Helper functions for report card comment integration with academic_comments table

/**
 * Get head teacher comment based on student performance
 */
function getHeadTeacherComment($pdo, $student_id, $academic_year_id, $term_id, $level_id,
    $total_points = null, $grade_counts = null) {
    return getCommentByType($pdo, $student_id, $academic_year_id, $term_id, $level_id,
        'head_teacher', $total_points, $grade_counts);
}

/**
 * Get class teacher comment based on student performance
 */
function getClassTeacherComment($pdo, $student_id, $academic_year_id, $term_id, $level_id,
    $total_points = null, $grade_counts = null) {
    return getCommentByType($pdo, $student_id, $academic_year_id, $term_id, $level_id,
        'class_teacher', $total_points, $grade_counts);
}

/**
 * Get comment based on type and performance criteria from academic_comments table
 */
function getCommentByType($pdo, $student_id, $academic_year_id, $term_id, $level_id,
    $comment_type, $total_points = null, $grade_counts = null) {
    try {
        // First, get student's class and stream
        $stmt = $pdo->prepare("
            SELECT class_id, stream_id
            FROM students
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            error_log("Student not found for comment lookup: ID $student_id");
            return getDefaultComment($level_id, $comment_type, $total_points, $grade_counts);
        }
        
        $class_id = $student['class_id'];
        $stream_id = $student['stream_id'];
        
        // Check if academic_comments table exists
        $tableExists = checkCommentsTableExists($pdo);
        if (!$tableExists) {
            return getDefaultComment($level_id, $comment_type, $total_points, $grade_counts);
        }
        
        // Build base query
        $sql = "
            SELECT comment, 
                   CASE WHEN class_id IS NOT NULL THEN 1 ELSE 0 END as class_match,
                   CASE WHEN stream_id IS NOT NULL THEN 1 ELSE 0 END as stream_match
            FROM academic_comments
            WHERE academic_year_id = ?
            AND level_id = ?
            AND term_id = ?
            AND comment_type = ?
            AND is_active = 1
        ";
        $params = [$academic_year_id, $level_id, $term_id, $comment_type];
        
        // Match by class (exact match or NULL for all classes)
        $sql .= " AND (class_id = ? OR class_id IS NULL)";
        $params[] = $class_id;
        
        // Match by stream (exact match or NULL for all streams)
        $sql .= " AND (stream_id = ? OR stream_id IS NULL)";
        $params[] = $stream_id;
        
        // Add criteria based on level
        if ($level_id == 2 && $total_points !== null) { // A-Level
            $sql .= " AND (min_points IS NULL OR ? >= min_points)";
            $sql .= " AND (max_points IS NULL OR ? <= max_points)";
            $params[] = $total_points;
            $params[] = $total_points;
        } else if ($level_id == 1 && is_array($grade_counts)) { // O-Level
            // Build grade count conditions - use OR logic for flexibility
            $gradeConditions = [];
            
            if (isset($grade_counts['A']) && $grade_counts['A'] > 0) {
                $gradeConditions[] = "(min_a_count IS NULL OR ? >= min_a_count)";
                $params[] = $grade_counts['A'];
            }
            if (isset($grade_counts['B']) && $grade_counts['B'] > 0) {
                $gradeConditions[] = "(min_b_count IS NULL OR ? >= min_b_count)";
                $params[] = $grade_counts['B'];
            }
            if (isset($grade_counts['C']) && $grade_counts['C'] > 0) {
                $gradeConditions[] = "(min_c_count IS NULL OR ? >= min_c_count)";
                $params[] = $grade_counts['C'];
            }
            if (isset($grade_counts['D']) && $grade_counts['D'] > 0) {
                $gradeConditions[] = "(min_d_count IS NULL OR ? >= min_d_count)";
                $params[] = $grade_counts['D'];
            }
            if (isset($grade_counts['E']) && $grade_counts['E'] > 0) {
                $gradeConditions[] = "(min_e_count IS NULL OR ? >= min_e_count)";
                $params[] = $grade_counts['E'];
            }
            
            if (!empty($gradeConditions)) {
                $sql .= " AND (" . implode(' AND ', $gradeConditions) . ")";
            }
        }
        
        // Order by specificity (most specific first)
        $sql .= " ORDER BY 
            class_match DESC,
            stream_match DESC,
            CASE 
                WHEN level_id = 2 THEN COALESCE(min_points, 0)
                ELSE COALESCE(min_a_count, 0) + COALESCE(min_b_count, 0) + 
                     COALESCE(min_c_count, 0) + COALESCE(min_d_count, 0) + COALESCE(min_e_count, 0)
            END DESC
            LIMIT 1
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($comment && !empty($comment['comment'])) {
            return $comment['comment'];
        }
        
        // Fallback to default comment
        return getDefaultComment($level_id, $comment_type, $total_points, $grade_counts);
        
    } catch (Exception $e) {
        error_log("Error getting comment: " . $e->getMessage());
        return getDefaultComment($level_id, $comment_type, $total_points, $grade_counts);
    }
}

/**
 * Check if academic_comments table exists
 */
function checkCommentsTableExists($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'academic_comments'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get default comment based on performance when no specific comment is found
 */
function getDefaultComment($level_id, $comment_type, $total_points = null, $grade_counts = null) {
    try {
        if ($level_id == 2 && $total_points !== null) {
            // A-Level default comments based on total points
            if ($total_points >= 24) {
                return "Excellent performance. Maintain this outstanding standard.";
            } elseif ($total_points >= 18) {
                return "Very good performance. Keep up the good work.";
            } elseif ($total_points >= 12) {
                return "Good performance. There is room for improvement.";
            } elseif ($total_points >= 6) {
                return "Satisfactory performance. More effort is needed.";
            } else {
                return "Needs significant improvement. Seek academic support.";
            }
        } elseif ($level_id == 1 && is_array($grade_counts)) {
            // O-Level default comments based on grade counts
            $total_passing = $grade_counts['A'] + $grade_counts['B'] + $grade_counts['C'] + $grade_counts['D'];
            $total_grades = array_sum($grade_counts);
            
            if ($grade_counts['A'] >= 5) {
                return "Outstanding performance. Excellent work!";
            } elseif ($total_passing >= 6) {
                return "Very good performance. Keep it up.";
            } elseif ($total_passing >= 4) {
                return "Good performance. Continue working hard.";
            } elseif ($total_grades >= 5) {
                return "Satisfactory performance. More effort required.";
            } else {
                return "Needs improvement. Please seek academic guidance.";
            }
        }
        
        // Generic fallback
        return ($comment_type === 'head_teacher') 
            ? "Keep working hard and strive for excellence."
            : "A student with potential who should work harder to achieve better results.";
            
    } catch (Exception $e) {
        error_log("Error generating default comment: " . $e->getMessage());
        return "Keep working hard and strive for excellence.";
    }
}

/**
 * Calculate A-Level points and count passing grades
 * Passing grades: D1, D2, C3, C4, C5, C6
 */
function calculateALevelPointsAndCounts($pdo, $student_id, $academic_year_id, $term_id) {
    try {
        $principal_count = 0;
        $subsidiary_count = 0;
        $total_points = 0;
        
        // Get student's subjects and marks
        $stmt = $pdo->prepare("
            SELECT s.id, s.category, s.name,
                   m.a1, m.a2, m.a3, m.a4, m.a5, m.project_score, m.eoc_score
            FROM subjects s
            JOIN student_subjects ss ON s.id = ss.subject_id
            LEFT JOIN a_level_marks m ON s.id = m.subject_id
                AND m.student_id = ?
                AND m.academic_year_id = ?
                AND m.term_id = ?
            WHERE ss.student_id = ? AND s.status = 'active'
            ORDER BY s.category, s.name
        ");
        $stmt->execute([$student_id, $academic_year_id, $term_id, $student_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($subjects as $subject) {
            // Calculate total marks
            $activities = [];
            if (!empty($subject['a1']) && $subject['a1'] !== null) $activities[] = floatval($subject['a1']);
            if (!empty($subject['a2']) && $subject['a2'] !== null) $activities[] = floatval($subject['a2']);
            if (!empty($subject['a3']) && $subject['a3'] !== null) $activities[] = floatval($subject['a3']);
            if (!empty($subject['a4']) && $subject['a4'] !== null) $activities[] = floatval($subject['a4']);
            if (!empty($subject['a5']) && $subject['a5'] !== null) $activities[] = floatval($subject['a5']);
            
            $total = 0;
            if (!empty($activities)) {
                $average = array_sum($activities) / count($activities);
                $activity_score = ($average / 3) * 20;
                $total += $activity_score;
            }
            if (!empty($subject['project_score']) && $subject['project_score'] !== null) {
                $total += floatval($subject['project_score']);
            }
            if (!empty($subject['eoc_score']) && $subject['eoc_score'] !== null) {
                $total += floatval($subject['eoc_score']);
            }
            
            if ($total > 0) {
                // Get grade and points
                $gradeStmt = $pdo->prepare("
                    SELECT grade_letter, points
                    FROM alevel_grading_scale
                    WHERE subject_category = ? AND ? BETWEEN min_score AND max_score
                    LIMIT 1
                ");
                $gradeStmt->execute([$subject['category'], $total]);
                $grade = $gradeStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($grade) {
                    // Add points to total (ALL subjects contribute to total points)
                    if (is_numeric($grade['points'])) {
                        $total_points += $grade['points'];
                    }
                    
                    // Count passing grades (D1 through C6) by category
                    $passing_grades = ['D1', 'D2', 'C3', 'C4', 'C5', 'C6'];
                    if (in_array($grade['grade_letter'], $passing_grades)) {
                        if ($subject['category'] == 'principal') {
                            $principal_count++;
                        } elseif ($subject['category'] == 'subsidiary') {
                            $subsidiary_count++;
                        }
                    }
                }
            }
        }
        
        return [
            'principal_count' => $principal_count,
            'subsidiary_count' => $subsidiary_count,
            'total_points' => $total_points
        ];
    } catch (Exception $e) {
        error_log("Error calculating A-Level points: " . $e->getMessage());
        return [
            'principal_count' => 0,
            'subsidiary_count' => 0,
            'total_points' => 0
        ];
    }
}

/**
 * Calculate O-Level grade counts
 */
function calculateOLevelGradeCounts($pdo, $student_id, $academic_year_id, $term_id) {
    try {
        $grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
        
        // Get student's subjects and marks
        $stmt = $pdo->prepare("
            SELECT s.id, s.category, s.name,
                   m.a1, m.a2, m.a3, m.a4, m.a5, m.eoc_score
            FROM subjects s
            JOIN student_subjects ss ON s.id = ss.subject_id
            LEFT JOIN o_level_marks m ON s.id = m.subject_id
                AND m.student_id = ?
                AND m.academic_year_id = ?
                AND m.term_id = ?
            WHERE ss.student_id = ? AND s.status = 'active'
        ");
        $stmt->execute([$student_id, $academic_year_id, $term_id, $student_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($subjects as $subject) {
            // Calculate total marks
            $activities = [];
            if (!empty($subject['a1']) && $subject['a1'] !== null) $activities[] = floatval($subject['a1']);
            if (!empty($subject['a2']) && $subject['a2'] !== null) $activities[] = floatval($subject['a2']);
            if (!empty($subject['a3']) && $subject['a3'] !== null) $activities[] = floatval($subject['a3']);
            if (!empty($subject['a4']) && $subject['a4'] !== null) $activities[] = floatval($subject['a4']);
            if (!empty($subject['a5']) && $subject['a5'] !== null) $activities[] = floatval($subject['a5']);
            
            $total = 0;
            if (!empty($activities)) {
                $average = array_sum($activities) / count($activities);
                $total += ($average / 3) * 20;
            }
            if (!empty($subject['eoc_score']) && $subject['eoc_score'] !== null) {
                $total += floatval($subject['eoc_score']);
            }
            
            if ($total > 0) {
                // Get grade
                $gradeStmt = $pdo->prepare("
                    SELECT grade_letter
                    FROM olevel_grading_scale
                    WHERE level_id = 1 AND category = ? AND ? BETWEEN min_score AND max_score
                    LIMIT 1
                ");
                $gradeStmt->execute([$subject['category'], $total]);
                $grade = $gradeStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($grade && isset($grade_counts[$grade['grade_letter']])) {
                    $grade_counts[$grade['grade_letter']]++;
                }
            }
        }
        
        return $grade_counts;
    } catch (Exception $e) {
        error_log("Error calculating O-Level grade counts: " . $e->getMessage());
        return ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
    }
}
?>