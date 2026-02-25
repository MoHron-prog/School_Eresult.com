<?php
// add_student.php

require_once 'config.php';

// PRG: After successful registration, show message without POST data
if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $student_id = htmlspecialchars($_GET['student_id'] ?? 'N/A');
    $message = "Student registered successfully! Student ID: {$student_id}";
    $message_type = "success";
    $_POST = [];
    clearTempPhoto();
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Initialize variables
$message = $message ?? '';
$message_type = $message_type ?? '';
$student_id = '';
$preview_data = null;

// Fetch admin info
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) throw new Exception("Admin user not found.");
    $fullname = htmlspecialchars($admin['fullname']);
    $email = htmlspecialchars($admin['email']);
} catch (Exception $e) {
    $fullname = "Admin";
    $email = "—";
}

// Fetch school name for footer
$school_name = "School Management System";
try {
    $stmt = $pdo->prepare("SELECT school_name FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($school_info && !empty($school_info['school_name'])) {
        $school_name = htmlspecialchars($school_info['school_name']);
    }
} catch (Exception $e) {
    error_log("Error fetching school info: " . $e->getMessage());
}

// Data lists
$east_african_countries = ['Uganda', 'Kenya', 'Tanzania', 'Rwanda', 'Burundi', 'South Sudan', 'Ethiopia', 'Somalia', 'Djibouti', 'Eritrea'];
$uganda_districts = [
    'Kampala', 'Wakiso', 'Mukono', 'Jinja', 'Mbale', 'Gulu', 'Lira', 'Mbarara', 
    'Masaka', 'Hoima', 'Fort Portal', 'Arua', 'Soroti', 'Entebbe', 'Kabale', 
    'Mityana', 'Iganga', 'Bushenyi', 'Ntungamo', 'Kasese', 'Kamuli', 'Pallisa', 
    'Kapchorwa', 'Tororo', 'Kumi', 'Kitgum', 'Adjumani', 'Kotido', 'Moroto', 
    'Nakapiripirit', 'Sembabule', 'Rakai', 'Lyantonde', 'Kalangala', 'Buvuma', 
    'Kayunga', 'Luwero', 'Nakaseke', 'Mubende', 'Kiboga', 'Kyankwanzi', 'Masindi', 
    'Buliisa', 'Kiruhura', 'Ibanda', 'Isingiro', 'Ntoroko', 'Bundibugyo', 
    'Kyenjojo', 'Kabarole', 'Kamwenge', 'Kyegegwa', 'Kanungu', 'Rukungiri', 
    'Busia', 'Bugiri', 'Namayingo', 'Mayuge', 'Kaliro', 'Buyende', 'Amuria', 
    'Kaberamaido', 'Dokolo', 'Amolatar', 'Maracha', 'Yumbe', 'Moyo', 'Obongi', 
    'Lamwo', 'Agago', 'Otuke', 'Alebtong', 'Amuru', 'Nwoya', 'Omoro', 'Zombo', 
    'Pakwach', 'Nebbi'
];

// Standardized upload path
$target_dir = "uploads/photos/";
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}

// Ensure upload directory is writable
if (!is_writable($target_dir)) {
    chmod($target_dir, 0777);
}

// Generate Student ID
function generateStudentID($pdo, $level_id, $level_name, $academic_year_id)
{
    $stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
    $stmt->execute([$academic_year_id]);
    $academic_year = $stmt->fetchColumn();

    if (!$academic_year) {
        throw new Exception("Academic year not found.");
    }

    if (strpos($academic_year, '/') !== false) {
        $year_part = substr($academic_year, 2, 2);
    } else {
        $year_part = substr($academic_year, 2, 2);
    }

    $level_letter = ($level_name == 'A Level') ? 'A' : 'O';

    $stmt = $pdo->prepare("
        SELECT student_id FROM students 
        WHERE student_id LIKE ? 
        ORDER BY student_id DESC LIMIT 1
    ");
    $pattern = "NSS/{$year_part}/{$level_letter}/%";
    $stmt->execute([$pattern]);
    $last_id = $stmt->fetchColumn();

    if ($last_id) {
        $parts = explode('/', $last_id);
        $last_seq_str = end($parts);
        $last_seq = is_numeric($last_seq_str) ? (int)$last_seq_str : 0;
        $sequence = str_pad($last_seq + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $sequence = '001';
    }
    return "NSS/{$year_part}/{$level_letter}/{$sequence}";
}

// Handle Photo Upload - FIXED: Better handling for preview and permanent storage
function handlePhotoUpload($file, $student_id, $target_dir, $is_preview = false)
{
    if ($file['error'] === UPLOAD_ERR_OK) {
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_ext)) {
            throw new Exception("Only JPG, PNG, and GIF files are allowed.");
        }

        if ($file['size'] > 2097152) { // 2MB limit
            throw new Exception("File size must be less than 2MB.");
        }

        $image_info = getimagesize($file['tmp_name']);
        if (!$image_info) {
            throw new Exception("Uploaded file is not a valid image.");
        }

        // Generate unique photo name
        $clean_student_id = preg_replace('/[^a-zA-Z0-9]/', '_', $student_id);
        $timestamp = time();
        $photo_name = 'student_' . $clean_student_id . '_' . $timestamp . '.' . $file_ext;
        $full_path = $target_dir . $photo_name;

        if (!is_writable($target_dir)) {
            throw new Exception("Upload directory is not writable. Please check permissions.");
        }

        if ($is_preview) {
            // For preview, store the file in a temporary location and return info
            $temp_dir = sys_get_temp_dir() . '/school_uploads/';
            if (!is_dir($temp_dir)) {
                mkdir($temp_dir, 0777, true);
            }
            
            $temp_path = $temp_dir . $photo_name;
            
            if (move_uploaded_file($file['tmp_name'], $temp_path)) {
                return [
                    'original_name' => $file['name'],
                    'temp_path' => $temp_path,
                    'photo_name' => $photo_name,
                    'full_path' => $full_path,
                    'mime_type' => $image_info['mime'],
                    'size' => $file['size']
                ];
            } else {
                $error = error_get_last();
                throw new Exception("Failed to save temporary photo. Error: " . ($error['message'] ?? 'Unknown error'));
            }
        } else {
            // For final registration, move directly to permanent location
            if (move_uploaded_file($file['tmp_name'], $full_path)) {
                return [
                    'success' => true,
                    'path' => $full_path,
                    'filename' => $photo_name
                ];
            } else {
                $error = error_get_last();
                throw new Exception("Failed to save photo. Error: " . ($error['message'] ?? 'Unknown error'));
            }
        }
    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit (MAX_FILE_SIZE).',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
        ];
        throw new Exception($upload_errors[$file['error']] ?? 'Unknown upload error.');
    }
    return null;
}

// FIXED: Function to save photo from session temp file with better error handling
function savePhotoFromSession($session_photo_info, $student_id, $target_dir)
{
    if (!isset($session_photo_info['temp_path']) || !file_exists($session_photo_info['temp_path'])) {
        error_log("Temp photo file not found: " . ($session_photo_info['temp_path'] ?? 'No path'));
        return null;
    }

    if (!is_writable($target_dir)) {
        throw new Exception("Upload directory is not writable. Please check permissions.");
    }

    // Generate a new filename with the actual student ID
    $clean_student_id = preg_replace('/[^a-zA-Z0-9]/', '_', $student_id);
    $file_ext = pathinfo($session_photo_info['photo_name'], PATHINFO_EXTENSION);
    $timestamp = time();
    $new_photo_name = 'student_' . $clean_student_id . '_' . $timestamp . '.' . $file_ext;
    $full_path = $target_dir . $new_photo_name;

    // Copy from temporary location to permanent location
    if (copy($session_photo_info['temp_path'], $full_path)) {
        // Verify the file was copied successfully
        if (file_exists($full_path) && filesize($full_path) > 0) {
            // Clean up temporary file
            @unlink($session_photo_info['temp_path']);
            return [
                'success' => true,
                'path' => $full_path,
                'filename' => $new_photo_name
            ];
        } else {
            throw new Exception("Failed to verify copied photo file.");
        }
    } else {
        $error = error_get_last();
        throw new Exception("Failed to save photo from preview. Error: " . ($error['message'] ?? 'Unknown error'));
    }
}

// Clear temp photo function
function clearTempPhoto() {
    if (isset($_SESSION['temp_photo']) && !empty($_SESSION['temp_photo'])) {
        if (isset($_SESSION['temp_photo']['temp_path']) && file_exists($_SESSION['temp_photo']['temp_path'])) {
            @unlink($_SESSION['temp_photo']['temp_path']);
        }
        unset($_SESSION['temp_photo']);
    }
}

// Validate subject selection
function validateSubjectSelection($pdo, $level_id, $class_id, $selected_subjects)
{
    $stmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
    $stmt->execute([$level_id]);
    $level_name = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class_name = $stmt->fetchColumn();

    if ($level_name === 'O Level') {
        $stmt = $pdo->prepare("SELECT id, category FROM subjects WHERE level_id = ?");
        $stmt->execute([$level_id]);
        $all_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $compulsory_count = 0;
        $elective_count = 0;
        $selected_ids = array_map('intval', $selected_subjects);

        foreach ($all_subjects as $subject) {
            if (in_array($subject['id'], $selected_ids)) {
                if ($subject['category'] === 'compulsory') {
                    $compulsory_count++;
                } elseif ($subject['category'] === 'elective') {
                    $elective_count++;
                }
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE level_id = ? AND category = 'compulsory'");
        $stmt->execute([$level_id]);
        $total_compulsory = $stmt->fetchColumn();

        if ($compulsory_count != $total_compulsory) {
            return "For O Level, you must select all {$total_compulsory} compulsory subjects. You selected {$compulsory_count}.";
        }

        if (in_array($class_name, ['S.1', 'S.2'])) {
            if ($elective_count < 6) {
                return "For {$class_name}, you must select at least 6 elective subjects. You selected {$elective_count}.";
            }
        } elseif (in_array($class_name, ['S.3', 'S.4'])) {
            if ($elective_count != 2) {
                return "For {$class_name}, you must select exactly 2 elective subjects. You selected {$elective_count}.";
            }
        }
    } elseif ($level_name === 'A Level') {
        $principal_count = 0;
        $subsidiary_count = 0;

        if (!empty($selected_subjects)) {
            $placeholders = str_repeat('?,', count($selected_subjects) - 1) . '?';
            $stmt = $pdo->prepare("SELECT category FROM subjects WHERE id IN ($placeholders)");
            $stmt->execute($selected_subjects);
            $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($categories as $category) {
                if ($category === 'principal') {
                    $principal_count++;
                } elseif ($category === 'subsidiary') {
                    $subsidiary_count++;
                }
            }
        }

        if ($principal_count != 3) {
            return "For A Level, you must select exactly 3 principal subjects. You selected {$principal_count}.";
        }
        if ($subsidiary_count != 2) {
            return "For A Level, you must select exactly 2 subsidiary subjects. You selected {$subsidiary_count}.";
        }
    }

    return true;
}

// Function to check if student-subject combination already exists
function checkExistingStudentSubjects($pdo, $student_db_id, $subject_ids)
{
    if (empty($subject_ids)) {
        return [];
    }
    
    $placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
    $params = array_merge([$student_db_id], $subject_ids);
    
    $stmt = $pdo->prepare("
        SELECT subject_id 
        FROM student_subjects 
        WHERE student_id = ? AND subject_id IN ($placeholders)
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Handle AJAX requests for clearing temp photo
if (isset($_GET['action']) && $_GET['action'] === 'clear_temp_photo') {
    clearTempPhoto();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['preview'])) {
            // Store all form data in session for preview
            $_SESSION['preview_data'] = [
                'surname' => $_POST['surname'] ?? '',
                'other_names' => $_POST['other_names'] ?? '',
                'sex' => $_POST['sex'] ?? '',
                'date_of_birth' => $_POST['date_of_birth'] ?? '',
                'nationality' => $_POST['nationality'] ?? '',
                'home_district' => $_POST['home_district'] ?? '',
                'academic_year_id' => $_POST['academic_year_id'] ?? '',
                'level_id' => $_POST['level_id'] ?? '',
                'class_id' => $_POST['class_id'] ?? '',
                'stream_id' => $_POST['stream_id'] ?? '',
                'status_type' => $_POST['status_type'] ?? 'Day',
                'subjects' => $_POST['subjects'] ?? []
            ];

            $preview_data = $_SESSION['preview_data'];

            // Validate subject selection BEFORE preview
            if (!empty($preview_data['level_id']) && !empty($preview_data['class_id']) && !empty($preview_data['subjects'])) {
                $validation_result = validateSubjectSelection($pdo, $preview_data['level_id'], $preview_data['class_id'], $preview_data['subjects']);
                if ($validation_result !== true) {
                    throw new Exception($validation_result);
                }
            }

            // Handle photo for preview
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                // Clear any existing temp photo
                clearTempPhoto();
                
                $temp_student_id = 'TEMP_' . time();
                $photo_info = handlePhotoUpload($_FILES['photo'], $temp_student_id, $target_dir, true);

                if ($photo_info) {
                    $_SESSION['temp_photo'] = $photo_info;
                    
                    // Create base64 preview for display
                    $image_data = file_get_contents($photo_info['temp_path']);
                    if ($image_data !== false) {
                        $preview_data['photo_preview'] = 'data:' . $photo_info['mime_type'] . ';base64,' . base64_encode($image_data);
                        $preview_data['photo_name'] = $photo_info['original_name'];
                        $preview_data['has_photo'] = true;
                    }
                }
            } elseif (isset($_SESSION['temp_photo']) && file_exists($_SESSION['temp_photo']['temp_path'])) {
                // Use existing session temp photo
                $image_data = file_get_contents($_SESSION['temp_photo']['temp_path']);
                if ($image_data !== false) {
                    $mime_type = mime_content_type($_SESSION['temp_photo']['temp_path']);
                    $preview_data['photo_preview'] = 'data:' . $mime_type . ';base64,' . base64_encode($image_data);
                    $preview_data['photo_name'] = $_SESSION['temp_photo']['original_name'];
                    $preview_data['has_photo'] = true;
                }
            }

            // Fetch names for preview
            if ($preview_data['academic_year_id']) {
                $stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
                $stmt->execute([$preview_data['academic_year_id']]);
                $preview_data['academic_year_name'] = $stmt->fetchColumn();
            }
            if ($preview_data['level_id']) {
                $stmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
                $stmt->execute([$preview_data['level_id']]);
                $preview_data['level_name'] = $stmt->fetchColumn();
            }
            if ($preview_data['class_id']) {
                $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
                $stmt->execute([$preview_data['class_id']]);
                $preview_data['class_name'] = $stmt->fetchColumn();
            }
            if ($preview_data['stream_id']) {
                $stmt = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
                $stmt->execute([$preview_data['stream_id']]);
                $preview_data['stream_name'] = $stmt->fetchColumn();
            }
            if (!empty($preview_data['subjects'])) {
                $placeholders = str_repeat('?,', count($preview_data['subjects']) - 1) . '?';
                $stmt = $pdo->prepare("SELECT id, code, name, category FROM subjects WHERE id IN ($placeholders)");
                $stmt->execute($preview_data['subjects']);
                $preview_data['subject_details'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $preview_data['subjects_by_category'] = [];
                foreach ($preview_data['subject_details'] as $subject) {
                    $preview_data['subjects_by_category'][$subject['category']][] = $subject;
                }
            }
            if ($preview_data['level_id'] && $preview_data['academic_year_id']) {
                $stmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
                $stmt->execute([$preview_data['level_id']]);
                $level_name = $stmt->fetchColumn();
                if ($level_name) {
                    $preview_data['student_id'] = generateStudentID($pdo, $preview_data['level_id'], $level_name, $preview_data['academic_year_id']);
                }
            }
        } elseif (isset($_POST['register'])) {
            // FIXED: Retrieve data from session if coming from preview
            if (isset($_SESSION['preview_data']) && !empty($_SESSION['preview_data'])) {
                $post_data = $_SESSION['preview_data'];
                $from_preview = true;
            } else {
                $post_data = $_POST;
                $from_preview = false;
            }

            // Validate required fields
            $required = ['surname', 'other_names', 'sex', 'date_of_birth', 'nationality', 'home_district', 'academic_year_id', 'level_id', 'class_id', 'stream_id', 'status_type'];
            foreach ($required as $field) {
                if (empty($post_data[$field])) throw new Exception("Please fill in all required fields.");
            }
            
            if (empty($post_data['subjects']) || !is_array($post_data['subjects'])) {
                throw new Exception("Please select at least one subject.");
            }

            // Clean subjects array
            $selected_subjects = array_unique(array_map('intval', $post_data['subjects']));
            
            // Validate subject selection
            $validation_result = validateSubjectSelection($pdo, $post_data['level_id'], $post_data['class_id'], $selected_subjects);
            if ($validation_result !== true) {
                throw new Exception($validation_result);
            }

            // Generate student ID
            $stmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
            $stmt->execute([$post_data['level_id']]);
            $level_name = $stmt->fetchColumn();
            if (!$level_name) {
                throw new Exception("Selected level not found.");
            }

            $student_id = generateStudentID($pdo, $post_data['level_id'], $level_name, $post_data['academic_year_id']);

            // FIXED: Handle photo upload - prioritize session temp photo
            $photo_result = null;

            // Case 1: New file uploaded during registration
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $photo_result = handlePhotoUpload($_FILES['photo'], $student_id, $target_dir, false);
                clearTempPhoto();
            }
            // Case 2: Photo from preview mode (in session)
            elseif (isset($_SESSION['temp_photo']) && !empty($_SESSION['temp_photo'])) {
                $photo_result = savePhotoFromSession($_SESSION['temp_photo'], $student_id, $target_dir);
                clearTempPhoto();
            }

            // Start transaction
            $pdo->beginTransaction();

            try {
                // Check if student with this ID already exists
                $stmt = $pdo->prepare("SELECT id FROM students WHERE student_id = ?");
                $stmt->execute([$student_id]);
                if ($stmt->rowCount() > 0) {
                    throw new Exception("Student ID {$student_id} already exists. Please try again.");
                }

                // Get photo path for database
                $photo_path = null;
                if ($photo_result && isset($photo_result['path'])) {
                    $photo_path = $photo_result['path'];
                }

                // Insert student
                $stmt = $pdo->prepare("
                    INSERT INTO students 
                    (student_id, surname, other_names, sex, date_of_birth, nationality, 
                     home_district, photo, academic_year_id, level_id, class_id, stream_id, status, status_type) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $student_id,
                    htmlspecialchars($post_data['surname']),
                    htmlspecialchars($post_data['other_names']),
                    $post_data['sex'],
                    $post_data['date_of_birth'],
                    $post_data['nationality'],
                    $post_data['home_district'],
                    $photo_path,
                    $post_data['academic_year_id'],
                    $post_data['level_id'],
                    $post_data['class_id'],
                    $post_data['stream_id'],
                    'active',
                    $post_data['status_type']
                ]);

                $student_db_id = $pdo->lastInsertId();

                // Insert student subjects
                if (!empty($selected_subjects)) {
                    $existing_subjects = checkExistingStudentSubjects($pdo, $student_db_id, $selected_subjects);
                    
                    if (!empty($existing_subjects)) {
                        $pdo->rollBack();
                        throw new Exception("Student already has " . count($existing_subjects) . " subject(s) assigned. Please refresh and try again.");
                    }

                    $subject_stmt = $pdo->prepare("INSERT INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
                    
                    foreach ($selected_subjects as $subject_id) {
                        try {
                            $subject_stmt->execute([$student_db_id, $subject_id]);
                        } catch (PDOException $e) {
                            if ($e->errorInfo[1] == 1062) {
                                throw new Exception("Subject ID {$subject_id} is already assigned to this student.");
                            } else {
                                throw $e;
                            }
                        }
                    }
                }

                // Log activity
                $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'ADD_STUDENT', ?)");
                $log_stmt->execute([$_SESSION['user_id'], "Added student: {$student_id} - {$post_data['surname']} {$post_data['other_names']}"]);

                $pdo->commit();

                // Clear session data
                unset($_SESSION['preview_data']);
                clearTempPhoto();

                // Redirect to avoid resubmission
                header("Location: add_student.php?registered=1&student_id=" . urlencode($student_id));
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Don't clear temp photo on validation errors, keep it for next attempt
        if (strpos($message, 'subject') === false && strpos($message, 'validation') === false) {
            clearTempPhoto();
        }
    }
}

// Fetch data for dropdowns
$academic_years = $pdo->query("SELECT id, year_name FROM academic_years WHERE status = 'active' ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$levels = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Restore form data from session if available
if (isset($_SESSION['preview_data']) && !isset($_POST['preview']) && !isset($_POST['register'])) {
    $_POST = $_SESSION['preview_data'];
}

$classes = [];
if (!empty($_POST['level_id'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM classes WHERE level_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$_POST['level_id']]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$streams = [];
if (!empty($_POST['class_id'])) {
    $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE class_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$_POST['class_id']]);
    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$subjects_by_category = [];
if (!empty($_POST['level_id'])) {
    $stmt = $pdo->prepare("SELECT id, code, name, category FROM subjects WHERE level_id = ? AND status = 'active' ORDER BY category, name");
    $stmt->execute([$_POST['level_id']]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($subjects as $subject) {
        $subjects_by_category[$subject['category']][] = $subject;
    }
}

// Get subject requirements
$subject_requirements = '';
if (!empty($_POST['level_id']) && !empty($_POST['class_id'])) {
    $stmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
    $stmt->execute([$_POST['level_id']]);
    $level_name = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt->execute([$_POST['class_id']]);
    $class_name = $stmt->fetchColumn();

    if ($level_name === 'O Level') {
        if (in_array($class_name, ['S.1', 'S.2'])) {
            $subject_requirements = "For {$class_name}, you must select all compulsory subjects and at least 6 elective subjects.";
        } elseif (in_array($class_name, ['S.3', 'S.4'])) {
            $subject_requirements = "For {$class_name}, you must select all compulsory subjects and exactly 2 elective subjects.";
        }
    } elseif ($level_name === 'A Level') {
        $subject_requirements = "For A Level, you must select exactly 3 principal subjects and 2 subsidiary subjects.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Add Student - School Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        /* [CSS remains exactly the same as in the previous version] */
        :root {
            --primary: #1a2a6c;
            --secondary: #b21f1f;
            --sidebar-bg: #2c3e50;
            --sidebar-hover: #34495e;
            --text-light: #ecf0f1;
            --body-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-dark: #212529;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        body {
            display: flex;
            background-color: var(--body-bg);
            color: var(--text-dark);
            height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            color: var(--text-light);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.12);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem;
            min-height: 80px;
            background: var(--primary);
            font-weight: 700;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #3a506b;
            flex-shrink: 0;
        }

        .nav-menu {
            list-style: none;
            padding: 0;
            flex: 1;
            overflow-y: auto;
        }

        .nav-item {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.8rem 1.2rem;
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.98rem;
            transition: all 0.2s;
        }

        .nav-link:hover {
            background: var(--sidebar-hover);
            color: white;
        }

        .nav-link i {
            width: 22px;
            font-size: 0.95rem;
            margin-right: 12px;
            text-align: center;
            color: #95a5a6;
        }

        .nav-link:hover i {
            color: #ecf0f1;
        }

        .dropdown-toggle::after {
            content: "▶";
            margin-left: auto;
            font-size: 0.7rem;
            color: #7f8c8d;
            transition: transform 0.3s;
            transform: rotate(0deg);
        }

        .dropdown.active>.nav-link.dropdown-toggle::after,
        .nested.active>.nav-link.dropdown-toggle::after {
            transform: rotate(90deg);
            color: #ecf0f1;
        }

        .dropdown-menu,
        .nested-menu {
            list-style: none;
            padding-left: 1.2rem;
            max-height: 0;
            overflow: hidden;
            background: rgba(0, 0, 0, 0.1);
            transition: max-height 0.3s ease;
        }

        .dropdown.active>.dropdown-menu {
            max-height: 1000px;
            padding: 0.45rem 0 0.45rem 1.2rem;
        }

        .nested.active>.nested-menu {
            max-height: 500px;
            padding: 0.3rem 0 0.3rem 1.2rem;
            background: rgba(0, 0, 0, 0.15);
        }

        .nested-menu .nav-link {
            padding: 0.5rem 0.7rem;
            font-size: 0.9rem;
            color: #d5dbdb;
        }

        .nested-menu .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 0.9rem;
        }

        .logout-section {
            padding: 0.9rem 1.2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
            flex-shrink: 0;
        }

        .logout-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            color: #e74c3c;
            font-size: 0.98rem;
            padding: 0.65rem 0;
            cursor: pointer;
            text-align: left;
            font-weight: 600;
        }

        .logout-btn:hover {
            color: #c0392b;
        }

        .main-wrapper {
            margin-left: 280px;
            width: calc(100% - 280px);
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        .header {
            height: 80px;
            min-height: 80px;
            background: white;
            box-shadow: 0 1px 5px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.4rem;
            flex-shrink: 0;
            cursor: pointer;
        }

        .header:hover {
            background: #f0f4ff;
        }

        .admin-info h1 {
            font-size: 1.5rem;
            color: var(--primary);
            margin: 0;
        }

        .admin-info p {
            font-size: 1rem;
            color: #6c757d;
            margin-top: 4px;
        }

        .role-tag {
            background: var(--primary);
            color: white;
            padding: 3px 10px;
            border-radius: 14px;
            font-size: 0.95rem;
        }

        .main-content {
            padding: 1rem 1.4rem;
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .footer {
            padding: 0.8rem 1.4rem;
            background: white;
            border-top: 1px solid #e9ecef;
            text-align: center;
            font-size: 0.85rem;
            color: #6c757d;
            flex-shrink: 0;
            margin-top: auto;
            box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.05);
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .copyright {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .footer-links {
            display: flex;
            gap: 1rem;
        }

        .footer-links a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        .alert {
            padding: 0.8rem 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .page-title {
            color: var(--primary);
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            font-size: 1.8rem;
        }

        .form-container {
            background: white;
            border-radius: 6px;
            padding: 1.5rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-title {
            color: var(--primary);
            margin: 1.5rem 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            font-size: 1.1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }

        .form-group label.required::after {
            content: " *";
            color: #dc3545;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        .form-control:disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }

        .text-muted {
            color: #6c757d;
            font-size: 0.8rem;
            margin-top: 0.25rem;
            display: block;
        }

        .text-danger {
            color: #dc3545;
        }

        .photo-preview-container {
            margin-top: 0.5rem;
            display: none;
        }

        .photo-preview {
            width: 120px;
            height: 120px;
            border-radius: 4px;
            object-fit: cover;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .subjects-container {
            margin-top: 1rem;
        }

        .requirements-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 0.8rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .requirements-box i {
            font-size: 1.1rem;
            color: #856404;
        }

        .category-group {
            margin-bottom: 1.5rem;
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            border-left: 4px solid var(--primary);
        }

        .category-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.8rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
        }

        .subject-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .subject-checkbox input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .subject-checkbox input[type="checkbox"]:disabled {
            cursor: not-allowed;
        }

        .subject-label {
            font-size: 0.9rem;
            color: #495057;
            cursor: pointer;
        }

        .subject-code {
            font-weight: 600;
            color: var(--secondary);
        }

        .form-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #0f1d4d;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
            animation: fadeIn 0.3s ease;
        }

        .preview-content {
            background: white;
            border-radius: 6px;
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .preview-header {
            background: var(--primary);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 6px 6px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .preview-header h3 {
            margin: 0;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            padding: 0 5px;
            transition: transform 0.2s;
        }

        .close-btn:hover {
            transform: scale(1.2);
        }

        .preview-body {
            padding: 1.5rem;
        }

        .preview-section {
            margin-bottom: 1.5rem;
        }

        .preview-section h4 {
            color: var(--primary);
            margin-bottom: 0.8rem;
            padding-bottom: 0.3rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .preview-item {
            margin-bottom: 0.5rem;
        }

        .preview-item strong {
            display: inline-block;
            min-width: 120px;
            color: #495057;
        }

        .preview-photo-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .preview-photo {
            width: 150px;
            height: 150px;
            border-radius: 4px;
            object-fit: cover;
            border: 1px solid #dee2e6;
        }

        .subject-category-preview {
            margin-bottom: 1rem;
        }

        .subject-category-preview h5 {
            color: var(--primary);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .subject-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .subject-badge {
            background: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.85rem;
        }

        .compulsory-badge {
            background: #d4edda;
            color: #155724;
        }

        .elective-badge {
            background: #d1ecf1;
            color: #0c5460;
        }

        .principal-badge {
            background: #fff3cd;
            color: #856404;
        }

        .subsidiary-badge {
            background: #f8d7da;
            color: #721c24;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                transition: width 0.3s;
                overflow-x: hidden;
            }

            .sidebar:hover {
                width: 280px;
            }

            .sidebar .sidebar-header span,
            .sidebar .nav-link span,
            .sidebar .dropdown-toggle::after,
            .sidebar .nested>.nav-link::after {
                opacity: 0;
                transition: opacity 0.3s;
                white-space: nowrap;
            }

            .sidebar:hover .sidebar-header span,
            .sidebar:hover .nav-link span,
            .sidebar:hover .dropdown-toggle::after,
            .sidebar:hover .nested>.nav-link::after {
                opacity: 1;
            }

            .sidebar .nav-link {
                justify-content: center;
                padding: 0.8rem;
            }

            .sidebar:hover .nav-link {
                justify-content: flex-start;
                padding: 0.8rem 1.2rem;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 1.1rem;
            }

            .sidebar:hover .nav-link i {
                margin-right: 12px;
            }

            .dropdown-menu,
            .nested-menu {
                display: none !important;
            }

            .sidebar:hover .dropdown.active>.dropdown-menu,
            .sidebar:hover .nested.active>.nested-menu {
                display: block !important;
            }

            .main-wrapper {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .sidebar:hover+.main-wrapper {
                margin-left: 280px;
                width: calc(100% - 280px);
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 0.8rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .form-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .footer-content {
                flex-direction: column;
                text-align: center;
                gap: 0.8rem;
            }
            
            .footer-links {
                justify-content: center;
            }

            .preview-photo-container {
                flex-direction: column;
                align-items: flex-start;
            }

            .subjects-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .header {
                height: auto;
                padding: 0.8rem 1rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.8rem;
            }

            .admin-info h1 {
                font-size: 1.3rem;
            }

            .role-tag {
                align-self: flex-start;
            }

            .preview-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <aside class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-school"></i>
            <span>School Admin</span>
        </div>
        <ul class="nav-menu">
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Teacher Management</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_teacher.php" class="nav-link">Add Teacher</a></li>
                    <li><a href="assign_subjects.php" class="nav-link">Assign Subjects</a></li>
                    <li><a href="teachers.php" class="nav-link">View Teachers</a></li>
                    <li><a href="edit_teachers.php" class="nav-link">Edit Teachers</a></li>
                    <li><a href="delete_teacher.php" class="nav-link">Delete Teacher</a></li>
                </ul>
            </li>

            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-user-graduate"></i>
                    <span>Student Management</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_student.php" class="nav-link">Add Learner</a></li>
                    <li><a href="students.php" class="nav-link">View Learners</a></li>
                    <li><a href="promote_students.php" class="nav-link">Promote Learners</a></li>
                    <li><a href="archive_students.php" class="nav-link">Archive Learners</a></li>
                    <li><a href="archived_students.php" class="nav-link">View Archived Learners</a></li>
                </ul>
            </li>

            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-school"></i>
                    <span>Classes & Stream</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_level.php" class="nav-link">Add Level</a></li>
                    <li><a href="manage_levels.php" class="nav-link">Manage Levels</a></li>
                    <li><a href="add_class.php" class="nav-link">Add Class</a></li>
                    <li><a href="manage_classes.php" class="nav-link">Manage Classes</a></li>
                    <li><a href="add_stream.php" class="nav-link">Add Stream</a></li>
                    <li><a href="manage_streams.php" class="nav-link">Manage Streams</a></li>
                </ul>
            </li>

            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-book"></i>
                    <span>Subjects Management</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_subject.php" class="nav-link">Add Subject</a></li>
                    <li><a href="subjects.php" class="nav-link">View Subjects</a></li>
                    <li><a href="manage_subjects.php" class="nav-link">Manage Subjects</a></li>
                </ul>
            </li>

            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-chart-bar"></i>
                    <span>Assessment</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nested">
                        <a href="#" class="nav-link dropdown-toggle">
                            <span>O-Level Assessment</span>
                        </a>
                        <ul class="nested-menu">
                            <li><a href="enter_marks.php" class="nav-link">Add Marks</a></li>
                            <li><a href="marksheets.php" class="nav-link">View Marks</a></li>
                            <li><a href="grading_o_level.php" class="nav-link">Set Grading</a></li>
                        </ul>
                    </li>
                    <li class="nested">
                        <a href="#" class="nav-link dropdown-toggle">
                            <span>A-Level Assessment</span>
                        </a>
                        <ul class="nested-menu">
                            <li><a href="enter_marks.php" class="nav-link">Add Marks</a></li>
                            <li><a href="marksheets.php" class="nav-link">View Marks</a></li>
                            <li><a href="grading_a_level.php" class="nav-link">Set Grading</a></li>
                        </ul>
                    </li>
                    <li><a href="reports.php" class="nav-link">Reports</a></li>
                    <li><a href="academic_calendar.php" class="nav-link">Academic Calendar</a></li>
                </ul>
            </li>

            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-cog"></i>
                    <span>More</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="export_logs.php" class="nav-link">Export Logs</a></li>
                    <li><a href="settings.php" class="nav-link">Settings</a></li>
                    <li><a href="profile.php" class="nav-link">Profile</a></li>
                </ul>
            </li>
        </ul>
        <div class="logout-section">
            <button class="logout-btn" onclick="window.location='logout.php'">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </div>
    </aside>

    <div class="main-wrapper">
        <header class="header" onclick="window.location='admin_dashboard.php'">
            <div class="admin-info">
                <h1>Welcome back, <?= $fullname ?>!</h1>
                <p><?= $email ?></p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <?php if (!empty($message)): ?>
                <div class="alert <?= $message_type ?>">
                    <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <h2 class="page-title">
                <i class="fas fa-user-plus"></i>
                Register New Student
            </h2>

            <form method="POST" enctype="multipart/form-data" id="studentForm">
                <div class="form-container">
                    <h3 class="section-title">
                        <i class="fas fa-graduation-cap"></i>
                        Academic Information
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="academic_year_id" class="required">Academic Year</label>
                            <select name="academic_year_id" id="academic_year_id" class="form-control" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= $year['id'] ?>" <?= (isset($_POST['academic_year_id']) && $_POST['academic_year_id'] == $year['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year['year_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($academic_years)): ?>
                                <small class="text-danger">No academic years found. Please add academic years first.</small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="level_id" class="required">Level</label>
                            <select name="level_id" id="level_id" class="form-control" required onchange="this.form.submit()">
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['id'] ?>" <?= (isset($_POST['level_id']) && $_POST['level_id'] == $level['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="class_id" class="required">Class</label>
                            <select name="class_id" id="class_id" class="form-control" required onchange="this.form.submit()" <?= empty($classes) ? 'disabled' : '' ?>>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="stream_id" class="required">Stream</label>
                            <select name="stream_id" id="stream_id" class="form-control" required <?= empty($streams) ? 'disabled' : '' ?>>
                                <option value="">Select Stream</option>
                                <?php foreach ($streams as $stream): ?>
                                    <option value="<?= $stream['id'] ?>" <?= (isset($_POST['stream_id']) && $_POST['stream_id'] == $stream['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($stream['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="status_type" class="required">Status</label>
                            <select name="status_type" id="status_type" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="Day" <?= (isset($_POST['status_type']) && $_POST['status_type'] == 'Day') ? 'selected' : '' ?>>Day</option>
                                <option value="Boarding" <?= (isset($_POST['status_type']) && $_POST['status_type'] == 'Boarding') ? 'selected' : '' ?>>Boarding</option>
                            </select>
                        </div>
                    </div>

                    <h3 class="section-title">
                        <i class="fas fa-user"></i>
                        Personal Information
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="surname" class="required">Surname</label>
                            <input type="text" name="surname" id="surname" class="form-control" required 
                                   value="<?= htmlspecialchars($_POST['surname'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="other_names" class="required">Other Names</label>
                            <input type="text" name="other_names" id="other_names" class="form-control" required 
                                   value="<?= htmlspecialchars($_POST['other_names'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="sex" class="required">Sex</label>
                            <select name="sex" id="sex" class="form-control" required>
                                <option value="">Select Sex</option>
                                <option value="male" <?= (isset($_POST['sex']) && $_POST['sex'] == 'male') ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= (isset($_POST['sex']) && $_POST['sex'] == 'female') ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="date_of_birth" class="required">Date of Birth</label>
                            <input type="date" name="date_of_birth" id="date_of_birth" class="form-control" required 
                                   value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>" 
                                   max="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="form-group">
                            <label for="nationality" class="required">Nationality</label>
                            <select name="nationality" id="nationality" class="form-control" required>
                                <option value="">Select Nationality</option>
                                <?php foreach ($east_african_countries as $country): ?>
                                    <option value="<?= $country ?>" <?= (isset($_POST['nationality']) && $_POST['nationality'] == $country) ? 'selected' : '' ?>>
                                        <?= $country ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="home_district" class="required">Home District</label>
                            <select name="home_district" id="home_district" class="form-control" required>
                                <option value="">Select District</option>
                                <?php foreach ($uganda_districts as $district): ?>
                                    <option value="<?= $district ?>" <?= (isset($_POST['home_district']) && $_POST['home_district'] == $district) ? 'selected' : '' ?>>
                                        <?= $district ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="photo">Student Photo</label>
                            <input type="file" name="photo" id="photo" class="form-control" 
                                   accept="image/jpeg,image/png,image/gif" onchange="previewPhoto(event)">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> Max 2MB, JPG/PNG/GIF only. 
                                Photos will be saved in uploads/photos/
                            </small>
                            <div class="photo-preview-container" id="photoPreviewContainer">
                                <img id="photoPreview" class="photo-preview" src="" alt="Photo Preview">
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($subjects_by_category) && !empty($_POST['level_id']) && !empty($_POST['class_id'])): ?>
                        <h3 class="section-title">
                            <i class="fas fa-book"></i>
                            Select Subjects
                        </h3>
                        
                        <?php if (!empty($subject_requirements)): ?>
                            <div class="requirements-box">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>Subject Requirements:</strong> <?= $subject_requirements ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="subjects-container">
                            <?php
                            $category_icons = [
                                'compulsory' => 'fas fa-check-circle',
                                'elective' => 'fas fa-list',
                                'principal' => 'fas fa-star',
                                'subsidiary' => 'fas fa-book'
                            ];

                            foreach ($subjects_by_category as $category => $subjects):
                                $category_title = ucfirst($category) . ' Subjects';
                                if ($category === 'compulsory') {
                                    $category_title .= ' (Required)';
                                } elseif ($category === 'elective') {
                                    if (isset($_POST['class_id']) && in_array($_POST['class_id'], [3, 4])) {
                                        $category_title .= ' (Select at least 6)';
                                    } elseif (isset($_POST['class_id']) && in_array($_POST['class_id'], [5, 6])) {
                                        $category_title .= ' (Select exactly 2)';
                                    }
                                } elseif ($category === 'principal') {
                                    $category_title .= ' (Select exactly 3)';
                                } elseif ($category === 'subsidiary') {
                                    $category_title .= ' (Select exactly 2)';
                                }
                            ?>
                                <div class="category-group">
                                    <div class="category-title">
                                        <i class="<?= $category_icons[$category] ?? 'fas fa-book' ?>"></i>
                                        <?= $category_title ?>
                                    </div>
                                    <div class="subjects-grid">
                                        <?php foreach ($subjects as $subject): ?>
                                            <div class="subject-checkbox">
                                                <?php if ($category === 'compulsory'): ?>
                                                    <input type="checkbox"
                                                        name="subjects[]"
                                                        id="subject_<?= $subject['id'] ?>"
                                                        value="<?= $subject['id'] ?>"
                                                        checked
                                                        disabled>
                                                    <input type="hidden" name="subjects[]" value="<?= $subject['id'] ?>">
                                                <?php else: ?>
                                                    <input type="checkbox"
                                                        name="subjects[]"
                                                        id="subject_<?= $subject['id'] ?>"
                                                        value="<?= $subject['id'] ?>"
                                                        <?= (isset($_POST['subjects']) && in_array($subject['id'], $_POST['subjects'])) ? 'checked' : '' ?>>
                                                <?php endif; ?>
                                                <label for="subject_<?= $subject['id'] ?>" class="subject-label">
                                                    <span class="subject-code"><?= htmlspecialchars($subject['code']) ?></span> 
                                                    - <?= htmlspecialchars($subject['name']) ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif (isset($_POST['level_id']) && !empty($_POST['level_id'])): ?>
                        <div class="alert error">
                            <i class="fas fa-exclamation-circle"></i>
                            No subjects found for this level. Please add subjects first.
                        </div>
                    <?php endif; ?>

                    <div class="form-buttons">
                        <button type="submit" name="preview" class="btn btn-secondary">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                        <button type="submit" name="register" class="btn btn-success">
                            <i class="fas fa-save"></i> Register Student
                        </button>
                        <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                        <a href="students.php" class="btn btn-primary">
                            <i class="fas fa-list"></i> View All Students
                        </a>
                    </div>
                </div>
            </form>
        </main>

        <footer class="footer">
            <div class="footer-content">
                <div class="copyright">
                    <i class="far fa-copyright"></i>
                    <span><?= date('Y') ?> <?= $school_name ?>. All rights reserved.</span>
                </div>
                <div class="footer-links">
                    <a href="privacy.php">Privacy Policy</a>
                    <a href="terms.php">Terms of Service</a>
                    <a href="contact.php">Contact Us</a>
                </div>
            </div>
        </footer>
    </div>

    <?php if ($preview_data): ?>
        <div class="preview-modal">
            <div class="preview-content">
                <div class="preview-header">
                    <h3>
                        <i class="fas fa-user-check"></i>
                        Student Preview
                    </h3>
                    <button class="close-btn" onclick="closePreview()">&times;</button>
                </div>
                <div class="preview-body">
                    <div class="preview-section">
                        <h4><i class="fas fa-graduation-cap"></i> Academic Information</h4>
                        <div class="preview-grid">
                            <div class="preview-item">
                                <strong>Student ID:</strong> 
                                <span style="color: var(--secondary); font-weight: 600;"><?= $preview_data['student_id'] ?? 'Not generated yet' ?></span>
                            </div>
                            <div class="preview-item"><strong>Academic Year:</strong> <?= $preview_data['academic_year_name'] ?? 'Not selected' ?></div>
                            <div class="preview-item"><strong>Level:</strong> <?= $preview_data['level_name'] ?? 'Not selected' ?></div>
                            <div class="preview-item"><strong>Class:</strong> <?= $preview_data['class_name'] ?? 'Not selected' ?></div>
                            <div class="preview-item"><strong>Stream:</strong> <?= $preview_data['stream_name'] ?? 'Not selected' ?></div>
                            <div class="preview-item"><strong>Status:</strong> <?= $preview_data['status_type'] ?? 'Not selected' ?></div>
                        </div>
                    </div>

                    <div class="preview-section">
                        <h4><i class="fas fa-user"></i> Personal Information</h4>
                        <div class="preview-grid">
                            <div class="preview-item"><strong>Surname:</strong> <?= htmlspecialchars($preview_data['surname']) ?></div>
                            <div class="preview-item"><strong>Other Names:</strong> <?= htmlspecialchars($preview_data['other_names']) ?></div>
                            <div class="preview-item"><strong>Sex:</strong> <?= ucfirst($preview_data['sex'] ?? '') ?></div>
                            <div class="preview-item"><strong>Date of Birth:</strong> <?= !empty($preview_data['date_of_birth']) ? date('F j, Y', strtotime($preview_data['date_of_birth'])) : 'Not provided' ?></div>
                            <div class="preview-item"><strong>Nationality:</strong> <?= htmlspecialchars($preview_data['nationality'] ?? '') ?></div>
                            <div class="preview-item"><strong>Home District:</strong> <?= htmlspecialchars($preview_data['home_district'] ?? '') ?></div>
                            <?php if (isset($preview_data['photo_preview'])): ?>
                                <div class="preview-item">
                                    <strong>Photo:</strong>
                                    <div class="preview-photo-container">
                                        <img src="<?= $preview_data['photo_preview'] ?>" alt="Student Photo Preview" class="preview-photo">
                                        <span style="color: #6c757d;"><?= htmlspecialchars($preview_data['photo_name']) ?></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="preview-item"><strong>Photo:</strong> No photo uploaded</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($preview_data['subject_details'])): ?>
                        <div class="preview-section preview-subjects">
                            <h4><i class="fas fa-book"></i> Selected Subjects (<?= count($preview_data['subject_details']) ?>)</h4>
                            <?php if (!empty($preview_data['subjects_by_category'])): ?>
                                <?php foreach ($preview_data['subjects_by_category'] as $category => $subjects): ?>
                                    <div class="subject-category-preview">
                                        <h5><?= ucfirst($category) ?> Subjects (<?= count($subjects) ?>)</h5>
                                        <div class="subject-list">
                                            <?php foreach ($subjects as $subject): ?>
                                                <span class="subject-badge <?= $category ?>-badge">
                                                    <strong><?= htmlspecialchars($subject['code']) ?></strong>: <?= htmlspecialchars($subject['name']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-buttons" style="border-top: none; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-secondary" onclick="closePreview()">
                            <i class="fas fa-edit"></i> Edit Details
                        </button>
                        <!-- FIXED: Use JavaScript to submit the form with preview data -->
                        <button type="button" class="btn btn-success" onclick="confirmRegistration()">
                            <i class="fas fa-check"></i> Confirm Registration & Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Toggle top-level dropdowns
        document.querySelectorAll('.dropdown > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.dropdown');
                parent.classList.toggle('active');
            });
        });

        // Toggle nested dropdowns
        document.querySelectorAll('.nested > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.nested');
                parent.classList.toggle('active');
            });
        });

        // Fix sidebar hover behavior for mobile
        const sidebar = document.querySelector('.sidebar');
        if (window.innerWidth <= 992) {
            let hoverTimeout;

            sidebar.addEventListener('mouseenter', function() {
                clearTimeout(hoverTimeout);
                this.style.width = '280px';
            });

            sidebar.addEventListener('mouseleave', function() {
                hoverTimeout = setTimeout(() => {
                    this.style.width = '70px';
                }, 300);
            });
        }

        // Close preview modal
        function closePreview() {
            const previewModal = document.querySelector('.preview-modal');
            if (previewModal) {
                previewModal.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => {
                    previewModal.remove();
                }, 300);
            }
        }

        // FIXED: Confirm registration function - submits the form with preview data
        function confirmRegistration() {
            // Create a hidden form submission
            const form = document.getElementById('studentForm');
            
            // Create a hidden input to indicate registration
            const registerInput = document.createElement('input');
            registerInput.type = 'hidden';
            registerInput.name = 'register';
            registerInput.value = '1';
            form.appendChild(registerInput);
            
            // Submit the form
            form.submit();
        }

        // Reset form function
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                // Clear session data via AJAX
                fetch('add_student.php?action=clear_temp_photo', {
                    method: 'GET',
                    cache: 'no-cache'
                }).then(() => {
                    // Redirect to clear all POST data
                    window.location.href = 'add_student.php';
                }).catch(error => {
                    console.error('Error clearing temp photo:', error);
                    window.location.href = 'add_student.php';
                });
            }
        }

        // Photo preview function
        function previewPhoto(event) {
            const input = event.target;
            const previewContainer = document.getElementById('photoPreviewContainer');
            const preview = document.getElementById('photoPreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.style.display = 'block';
                }
                
                reader.onerror = function() {
                    showNotification('Error loading image preview!', 'error');
                }
                
                reader.readAsDataURL(input.files[0]);
            } else {
                previewContainer.style.display = 'none';
                preview.src = '';
            }
        }

        // Show notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 18px;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
                color: white;
                border-radius: 4px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                z-index: 10000;
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 8px;
                animation: slideIn 0.3s ease;
            `;

            let icon = 'fas fa-info-circle';
            if (type === 'success') icon = 'fas fa-check-circle';
            if (type === 'error') icon = 'fas fa-exclamation-circle';

            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Add animation styles
        if (!document.getElementById('notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%) translateY(-20px); opacity: 0; }
                    to { transform: translateX(0) translateY(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
                @keyframes fadeOut {
                    from { opacity: 1; transform: scale(1); }
                    to { opacity: 0; transform: scale(0.95); }
                }
            `;
            document.head.appendChild(style);
        }

        // Form validation before submission
        document.getElementById('studentForm').addEventListener('submit', function(e) {
            // Only validate if not submitting via preview
            if (e.submitter && e.submitter.name === 'register') {
                if (!document.getElementById('level_id').value) {
                    e.preventDefault();
                    showNotification('Please select a level first!', 'error');
                    return false;
                }

                if (!document.getElementById('class_id').value) {
                    e.preventDefault();
                    showNotification('Please select a class first!', 'error');
                    return false;
                }

                if (!document.getElementById('stream_id').value) {
                    e.preventDefault();
                    showNotification('Please select a stream!', 'error');
                    return false;
                }

                const subjects = document.querySelectorAll('input[name="subjects[]"]:checked, input[name="subjects[]"][type="hidden"]');
                if (subjects.length === 0) {
                    e.preventDefault();
                    showNotification('Please select at least one subject!', 'error');
                    return false;
                }
            }
            return true;
        });

        // Clean up temp photos on page unload
        window.addEventListener('beforeunload', function() {
            navigator.sendBeacon('add_student.php?action=clear_temp_photo');
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const classSelect = document.getElementById('class_id');
            const streamSelect = document.getElementById('stream_id');
            
            if (classSelect) classSelect.disabled = classSelect.options.length <= 1;
            if (streamSelect) streamSelect.disabled = streamSelect.options.length <= 1;

            <?php if (isset($_SESSION['temp_photo']) && file_exists($_SESSION['temp_photo']['temp_path'])): ?>
                // Create preview from session temp photo
                fetch('<?= $_SESSION['temp_photo']['temp_path'] ?>')
                    .then(response => response.blob())
                    .then(blob => {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            document.getElementById('photoPreview').src = e.target.result;
                            document.getElementById('photoPreviewContainer').style.display = 'block';
                        };
                        reader.readAsDataURL(blob);
                    })
                    .catch(error => console.error('Error loading preview:', error));
            <?php endif; ?>

            // Prevent clicking on disabled compulsory checkboxes
            document.querySelectorAll('input[type="checkbox"][disabled]').forEach(cb => {
                cb.addEventListener('click', function(e) {
                    e.preventDefault();
                });
            });
        });
    </script>
</body>

</html>