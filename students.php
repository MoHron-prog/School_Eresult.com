<?php
// students.php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Handle AJAX requests for streams
if (isset($_GET['ajax_streams']) && isset($_GET['class_id'])) {
    $class_id = $_GET['class_id'];
    $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE class_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$class_id]);
    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($streams);
    exit;
}

// Handle AJAX requests for subjects
if (isset($_GET['ajax_subjects']) && isset($_GET['level_id'])) {
    $level_id = $_GET['level_id'];
    $stmt = $pdo->prepare("SELECT id, code, name, category FROM subjects WHERE level_id = ? AND status = 'active' ORDER BY category, name");
    $stmt->execute([$level_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($subjects);
    exit;
}

// Handle AJAX requests for student subjects
if (isset($_GET['ajax_student_subjects']) && isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];
    $stmt = $pdo->prepare("SELECT subject_id FROM student_subjects WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    header('Content-Type: application/json');
    echo json_encode($subjects);
    exit;
}

// Handle Print Student Profile request
if (isset($_GET['get_student_data']) && isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];
    
    // Get student details
    $stmt = $pdo->prepare("
        SELECT s.*, 
               l.name as level_name,
               c.name as class_name,
               st.name as stream_name,
               ay.year_name as academic_year,
               CONCAT(s.surname, ' ', s.other_names) as fullname
        FROM students s
        LEFT JOIN levels l ON s.level_id = l.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN streams st ON s.stream_id = st.id
        LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        die(json_encode(['error' => 'Student not found.']));
    }
    
    // Get student subjects
    $stmt = $pdo->prepare("
        SELECT sub.code, sub.name, sub.category
        FROM student_subjects ss
        LEFT JOIN subjects sub ON ss.subject_id = sub.id
        WHERE ss.student_id = ?
        ORDER BY sub.category, sub.code
    ");
    $stmt->execute([$student_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group subjects by category
    $subjects_by_category = [];
    foreach ($subjects as $subject) {
        $category = $subject['category'];
        if (!isset($subjects_by_category[$category])) {
            $subjects_by_category[$category] = [];
        }
        $subjects_by_category[$category][] = $subject;
    }
    
    // Calculate age
    $dob = new DateTime($student['date_of_birth']);
    $today = new DateTime();
    $age = $dob->diff($today)->y;
    
    $response = [
        'success' => true,
        'student' => $student,
        'age' => $age,
        'subjects_by_category' => $subjects_by_category,
        'total_subjects' => count($subjects)
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Fetch user info for permission check
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_role = $user['role'];
    
    // Check if teacher is a class teacher
    $teacher_classes = [];
    if ($user_role === 'teacher') {
        $stmt = $pdo->prepare("SELECT class_id FROM teacher_classes WHERE teacher_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $teacher_classes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    
    // Initialize filter variables from GET parameters
    $level_id = $_GET['level_id'] ?? '';
    $class_id = $_GET['class_id'] ?? '';
    $stream_id = $_GET['stream_id'] ?? '';
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? 'active';
    
    // Build query for fetching students with teacher restriction
    $query = "SELECT 
                s.student_id, 
                CONCAT(s.surname, ' ', s.other_names) as fullname,
                l.name as level_name, 
                c.name as class_name, 
                st.name as stream_name,
                s.sex,
                s.date_of_birth
              FROM students s
              LEFT JOIN levels l ON s.level_id = l.id
              LEFT JOIN classes c ON s.class_id = c.id
              LEFT JOIN streams st ON s.stream_id = st.id
              WHERE 1=1";

    $params = [];

    // Restrict teachers to their assigned classes
    if ($user_role === 'teacher' && !empty($teacher_classes)) {
        $query .= " AND s.class_id IN (" . implode(',', array_fill(0, count($teacher_classes), '?')) . ")";
        $params = array_merge($params, $teacher_classes);
    } elseif ($user_role === 'teacher') {
        // Teacher with no classes assigned - show no students
        $query .= " AND 1=0";
    }

    if ($level_id) {
        $query .= " AND s.level_id = ?";
        $params[] = $level_id;
    }

    if ($class_id) {
        $query .= " AND s.class_id = ?";
        $params[] = $class_id;
    }

    if ($stream_id) {
        $query .= " AND s.stream_id = ?";
        $params[] = $stream_id;
    }

    if ($status && $status !== 'all') {
        $query .= " AND s.status = ?";
        $params[] = $status;
    }

    if ($search) {
        $query .= " AND (s.student_id LIKE ? OR s.surname LIKE ? OR s.other_names LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $query .= " ORDER BY s.student_id ASC";

    // Fetch students for export
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_' . date('Y-m-d_H-i-s') . '.csv');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write headers
    fputcsv($output, ['Student ID', 'Full Name', 'Level', 'Class', 'Stream', 'Sex', 'Date of Birth']);
    
    // Write data
    foreach ($students as $student) {
        fputcsv($output, [
            $student['student_id'],
            $student['fullname'],
            $student['level_name'],
            $student['class_name'],
            $student['stream_name'],
            ucfirst($student['sex']),
            date('M j, Y', strtotime($student['date_of_birth']))
        ]);
    }
    
    fclose($output);
    exit;
}

// Fetch user info
try {
    $stmt = $pdo->prepare("SELECT fullname, email, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new Exception("User not found.");
    $fullname = htmlspecialchars($user['fullname']);
    $email = htmlspecialchars($user['email']);
    $user_role = $user['role'];
} catch (Exception $e) {
    $fullname = "User";
    $email = "—";
    $user_role = 'teacher';
}

// Fetch school name for footer
$school_name = "School Management System"; // Default value
try {
    $stmt = $pdo->prepare("SELECT school_name FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($school_info && !empty($school_info['school_name'])) {
        $school_name = htmlspecialchars($school_info['school_name']);
    }
} catch (Exception $e) {
    // Use default if query fails
    error_log("Error fetching school info: " . $e->getMessage());
}

// Check if teacher is a class teacher
$teacher_classes = [];
if ($user_role === 'teacher') {
    $stmt = $pdo->prepare("SELECT class_id FROM teacher_classes WHERE teacher_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher_classes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Initialize filter variables
$level_id = $_GET['level_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$stream_id = $_GET['stream_id'] ?? '';
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'active';

// Fetch data for dropdowns
$levels = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$classes = [];
if ($level_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM classes WHERE level_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$level_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$streams = [];
if ($class_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE class_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$class_id]);
    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Build query for fetching students with teacher restriction
$query = "SELECT 
            s.*, 
            l.name as level_name, 
            c.name as class_name, 
            st.name as stream_name,
            CONCAT(s.surname, ' ', s.other_names) as fullname
          FROM students s
          LEFT JOIN levels l ON s.level_id = l.id
          LEFT JOIN classes c ON s.class_id = c.id
          LEFT JOIN streams st ON s.stream_id = st.id
          WHERE 1=1";

$params = [];

// Restrict teachers to their assigned classes
if ($user_role === 'teacher' && !empty($teacher_classes)) {
    $query .= " AND s.class_id IN (" . implode(',', array_fill(0, count($teacher_classes), '?')) . ")";
    $params = array_merge($params, $teacher_classes);
} elseif ($user_role === 'teacher') {
    // Teacher with no classes assigned - show no students
    $query .= " AND 1=0";
}

if ($level_id) {
    $query .= " AND s.level_id = ?";
    $params[] = $level_id;
}

if ($class_id) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_id;
}

if ($stream_id) {
    $query .= " AND s.stream_id = ?";
    $params[] = $stream_id;
}

if ($status && $status !== 'all') {
    $query .= " AND s.status = ?";
    $params[] = $status;
}

if ($search) {
    $query .= " AND (s.student_id LIKE ? OR s.surname LIKE ? OR s.other_names LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY s.student_id ASC";

// Fetch students
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    // Only admin can delete
    if ($user_role !== 'admin') {
        $message = "Only administrators can delete students.";
        $message_type = "error";
    } else {
        try {
            $student_id = $_POST['delete_id'];
            
            // Get student info for logging
            $stmt = $pdo->prepare("SELECT student_id, surname, other_names, photo FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete student photo if exists
            if ($student['photo'] && file_exists($student['photo']) && !str_contains($student['photo'], 'data:image/svg+xml')) {
                @unlink($student['photo']);
            }
            
            // Delete student subjects
            $stmt = $pdo->prepare("DELETE FROM student_subjects WHERE student_id = ?");
            $stmt->execute([$student_id]);
            
            // Delete the student (permanent delete)
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            
            // Log the action
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'DELETE_STUDENT', ?)");
            $log_stmt->execute([$_SESSION['user_id'], "Deleted student: {$student['student_id']} - {$student['surname']} {$student['other_names']}"]);
            
            // Refresh page
            header("Location: students.php?" . http_build_query($_GET) . "&message=" . urlencode("Student deleted successfully!") . "&message_type=success");
            exit;
        } catch (Exception $e) {
            $message = "Error deleting student: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Handle student profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    try {
        $student_id = $_POST['student_id'];
        
        // Check permission
        if ($user_role === 'teacher') {
            // Teacher can only edit students in their assigned classes
            $stmt = $pdo->prepare("SELECT class_id FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student_class = $stmt->fetchColumn();
            
            if (!in_array($student_class, $teacher_classes)) {
                throw new Exception("You don't have permission to edit this student.");
            }
        }
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // 1. Handle photo upload
        $photo_path = $_POST['current_photo'] ?? null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $target_dir = "uploads/students/";
            if (!is_dir($target_dir)) {
                if (!mkdir($target_dir, 0777, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }
            
            $file = $_FILES['photo'];
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception("Only JPG, PNG, and GIF files are allowed.");
            }
            
            if ($file['size'] > 2097152) { // 2MB
                throw new Exception("File size must be less than 2MB.");
            }
            
            // Delete old photo if exists and is not the default
            if ($photo_path && file_exists($photo_path) && !str_contains($photo_path, 'data:image/svg+xml')) {
                @unlink($photo_path);
            }
            
            // Generate new filename
            $student_code = $_POST['student_code'] ?? 'student';
            $new_filename = preg_replace('/[^a-z0-9]/i', '_', $student_code) . '_' . time() . '.' . $file_ext;
            $full_path = $target_dir . $new_filename;
            
            if (!move_uploaded_file($file['tmp_name'], $full_path)) {
                throw new Exception("Failed to upload photo.");
            }
            
            $photo_path = $full_path;
        } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            // There was an upload error
            throw new Exception("File upload error: " . $_FILES['photo']['error']);
        }
        
        // 2. Update student information
        $update_stmt = $pdo->prepare("
            UPDATE students SET 
                surname = ?, 
                other_names = ?, 
                sex = ?, 
                date_of_birth = ?, 
                nationality = ?, 
                home_district = ?, 
                level_id = ?, 
                class_id = ?, 
                stream_id = ?, 
                status = ?,
                photo = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $update_stmt->execute([
            htmlspecialchars($_POST['surname']),
            htmlspecialchars($_POST['other_names']),
            $_POST['sex'],
            $_POST['date_of_birth'],
            $_POST['nationality'],
            $_POST['home_district'],
            $_POST['level_id'],
            $_POST['class_id'],
            $_POST['stream_id'],
            $_POST['status'],
            $photo_path,
            $student_id
        ]);
        
        // 3. Handle subjects update if subjects are provided
        if (isset($_POST['subjects']) && is_array($_POST['subjects'])) {
            // Delete existing subjects
            $delete_stmt = $pdo->prepare("DELETE FROM student_subjects WHERE student_id = ?");
            $delete_stmt->execute([$student_id]);
            
            // Insert new subjects
            $subject_stmt = $pdo->prepare("INSERT INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
            foreach ($_POST['subjects'] as $subject_id) {
                $subject_stmt->execute([$student_id, $subject_id]);
            }
        }
        
        // 4. Log the action
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'UPDATE_STUDENT', ?)");
        $log_stmt->execute([
            $_SESSION['user_id'], 
            "Updated student profile: {$_POST['student_code']} - {$_POST['surname']} {$_POST['other_names']}"
        ]);
        
        $pdo->commit();
        
        // Refresh to show updated data
        header("Location: students.php?" . http_build_query($_GET) . "&message=" . urlencode("Student profile updated successfully!") . "&message_type=success");
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "Error updating student: " . $e->getMessage();
        $message_type = "error";
    }
}

// Check for messages from redirect
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = $_GET['message_type'] ?? 'success';
}

// Fetch all active classes for edit modal
$all_classes = $pdo->query("SELECT id, name FROM classes WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Data for edit form (countries and districts)
$east_african_countries = ['Uganda','Kenya','Tanzania','Rwanda','Burundi','South Sudan','Ethiopia','Somalia','Djibouti','Eritrea'];
$uganda_districts = [
    'Kampala', 'Wakiso', 'Mukono', 'Jinja', 'Mbale', 'Gulu', 'Lira', 'Mbarara',
    'Masaka', 'Hoima', 'Fort Portal', 'Arua', 'Soroti', 'Entebbe', 'Kabale',
    'Mityana', 'Iganga', 'Bushenyi', 'Ntungamo', 'Kasese', 'Kamuli', 'Pallisa',
    'Kapchorwa', 'Tororo', 'Kumi', 'Kitgum', 'Adjumani', 'Kotido', 'Moroto',
    'Nakapiripirit', 'Sembabule', 'Rakai', 'Lyantonde', 'Kalangala', 'Buvuma',
    'Kayunga', 'Luwero', 'Nakaseke', 'Mubende', 'Kiboga', 'Kyankwanzi',
    'Masindi', 'Buliisa', 'Kiruhura', 'Ibanda', 'Isingiro', 'Kiruhura',
    'Ntoroko', 'Bundibugyo', 'Kyenjojo', 'Kabarole', 'Kamwenge', 'Kyegegwa',
    'Kanungu', 'Rukungiri', 'Busia', 'Bugiri', 'Namayingo', 'Mayuge',
    'Kaliro', 'Buyende', 'Amuria', 'Kaberamaido', 'Dokolo', 'Amolatar',
    'Maracha', 'Yumbe', 'Moyo', 'Obongi', 'Lamwo', 'Agago', 'Otuke',
    'Alebtong', 'Amuru', 'Nwoya', 'Omoro', 'Zombo', 'Pakwach', 'Nebbi'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Learners - School Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root { 
            --primary: #1a2a6c; 
            --secondary: #b21f1f; 
            --sidebar-bg: #2c3e50; 
            --sidebar-hover: #34495e; 
            --text-light: #ecf0f1; 
            --body-bg: #f8f9fa; 
            --card-bg: #ffffff; 
            --text-dark: #212529; 
            --danger: #dc3545;
            --warning: #ffc107;
            --success: #28a745;
            --info: #17a2b8;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        html, body { 
            height: 100%; 
            overflow: hidden; 
        }
        body { 
            display: flex; 
            height: 100vh; 
            background-color: var(--body-bg); 
            color: var(--text-dark); 
            overflow: hidden; 
        }
        
        /* Sidebar Styles - MATCHING ADMIN DASHBOARD */
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
            box-shadow: 2px 0 8px rgba(0,0,0,0.12); 
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
            border-bottom: 1px solid rgba(255,255,255,0.08); 
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
            background: rgba(0,0,0,0.1); 
            transition: max-height 0.3s ease; 
        }
        .dropdown.active>.dropdown-menu { 
            max-height: 1000px; 
            padding: 0.45rem 0 0.45rem 1.2rem; 
        }
        .nested.active>.nested-menu { 
            max-height: 500px; 
            padding: 0.3rem 0 0.3rem 1.2rem; 
            background: rgba(0,0,0,0.15); 
        }
        .nested-menu .nav-link { 
            padding: 0.5rem 0.7rem; 
            font-size: 0.9rem; 
            color: #d5dbdb; 
        }
        .nested-menu .nav-link:hover { 
            background: rgba(255,255,255,0.1); 
            padding-left: 0.9rem; 
        }
        .logout-section { 
            padding: 0.9rem 1.2rem; 
            border-top: 1px solid rgba(255,255,255,0.1); 
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
        
        /* Main Content */
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
            box-shadow: 0 1px 5px rgba(0,0,0,0.08); 
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
        }
        
        /* Footer - MATCHING ADMIN DASHBOARD */
        .footer { 
            padding: 0.8rem 1.4rem; 
            background: white; 
            border-top: 1px solid #e9ecef; 
            text-align: center; 
            font-size: 0.85rem; 
            color: #6c757d; 
            flex-shrink: 0; 
            margin-top: auto; 
            box-shadow: 0 -1px 3px rgba(0,0,0,0.05); 
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
        
        @media (max-width: 768px) {
            .footer-content { 
                flex-direction: column; 
                text-align: center; 
                gap: 0.8rem; 
            }
            .footer-links { 
                justify-content: center; 
            }
        }
        
        /* Messages */
        .alert { padding: 0.8rem 1rem; margin-bottom: 1rem; border-radius: 4px; font-size: 0.9rem; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert.info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        
        /* Page Title */
        .page-title { color: var(--primary); margin-bottom: 1.5rem; font-size: 1.8rem; display: flex; justify-content: space-between; align-items: center; }
        
        /* Filter Form */
        .filter-container { background: white; border-radius: 6px; padding: 1.5rem; box-shadow: 0 2px 6px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.3rem; font-weight: 600; color: #495057; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.9rem; transition: border-color 0.15s; }
        .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 0.2rem rgba(26,42,108,0.25); }
        .search-container { display: flex; gap: 0.5rem; }
        .search-input { flex: 1; }
        .btn { padding: 0.6rem 1.5rem; border: none; border-radius: 4px; font-size: 0.9rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #0f1d4d; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #218838; color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c82333; color: white; }
        .btn-info { background: var(--info); color: white; }
        .btn-info:hover { background: #138496; color: white; }
        .btn-warning { background: var(--warning); color: #212529; }
        .btn-warning:hover { background: #e0a800; color: #212529; }
        .btn-export { background: #20c997; color: white; }
        .btn-export:hover { background: #199d76; color: white; }
        
        /* Results Info */
        .results-info { background: #e9ecef; padding: 0.8rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem; color: #495057; }
        
        /* Students Table */
        .table-container { background: white; border-radius: 6px; overflow: hidden; box-shadow: 0 2px 6px rgba(0,0,0,0.05); margin-bottom: 2rem; }
        .table-responsive { overflow-x: auto; }
        .students-table { width: 100%; border-collapse: collapse; }
        .students-table th { background: var(--primary); color: white; padding: 0.8rem; text-align: left; font-weight: 600; font-size: 0.9rem; }
        .students-table td { padding: 0.8rem; border-bottom: 1px solid #dee2e6; font-size: 0.9rem; vertical-align: middle; }
        .students-table tr:hover { background: #f8f9fa; }
        .students-table .photo-cell { width: 60px; }
        .student-photo { width: 50px; height: 50px; border-radius: 60%; object-fit: contain; border: 2px solid #dee2e6; }
        .status-badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-graduated { background: #d1ecf1; color: #0c5460; }
        .status-transferred { background: #fff3cd; color: #856404; }
        .action-buttons { display: flex; gap: 0.5rem; }
        .action-btn { width: 32px; height: 32px; border-radius: 4px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: all 0.2s; }
        .edit-btn { background: var(--info); color: white; }
        .edit-btn:hover { background: #138496; }
        .delete-btn { background: var(--danger); color: white; }
        .delete-btn:hover { background: #c82333; }
        .print-btn { background: var(--success); color: white; }
        .print-btn:hover { background: #218838; }
        
        /* Export Button */
        .export-container { display: flex; justify-content: flex-end; margin-bottom: 1rem; }
        
        /* Print Modal */
        .print-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 1rem; }
        .print-modal-overlay.active { display: flex; }
        .print-modal-content { background: white; border-radius: 10px; width: 90%; max-width: 900px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .print-modal-header { background: var(--primary); color: white; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .print-modal-header h3 { margin: 0; font-size: 1.3rem; font-weight: 600; }
        .print-close-btn { background: none; border: none; color: white; font-size: 1.8rem; cursor: pointer; line-height: 1; opacity: 0.8; transition: opacity 0.2s; }
        .print-close-btn:hover { opacity: 1; }
        .print-modal-body { padding: 1.5rem; flex: 1; overflow-y: auto; }
        
        /* Print Preview Content */
        .print-preview-container { background: white; padding: 20px; }
        
        /* PRINT-SPECIFIC STYLES */
        @media print {
            @page {
                size: A4;
                margin: 0.5in;
            }
            
            body * {
                visibility: hidden;
            }
            
            .print-content, .print-content * {
                visibility: visible;
            }
            
            .print-content {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 0;
                margin: 0;
                font-family: 'Times New Roman', Times, serif;
                font-size: 11pt;
                line-height: 1.3;
            }
            
            .no-print {
                display: none !important;
            }
            
            .first-page-content {
                page-break-after: always;
                min-height: 9in;
            }
            
            .school-header {
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 3px solid #000;
                padding-bottom: 10px;
                page-break-after: avoid;
            }
            
            .school-name {
                font-size: 22pt;
                font-weight: bold;
                color: #000;
                margin: 0;
                line-height: 1.2;
                page-break-after: avoid;
            }
            
            .school-address {
                font-size: 10pt;
                color: #333;
                margin: 3px 0;
                page-break-after: avoid;
            }
            
            .document-title {
                font-size: 16pt;
                font-weight: bold;
                color: #000;
                text-align: center;
                margin: 15px 0 20px 0;
                text-decoration: underline;
                page-break-after: avoid;
            }
            
            .student-info-container {
                display: flex;
                margin-bottom: 15px;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            
            .photo-container {
                flex: 0 0 1.8in;
                margin-right: 20px;
                text-align: center;
                page-break-inside: avoid;
            }
            
            .print-student-photo {
                width: 1.5in !important;
                height: 1.8in !important;
                border: 2px solid #000;
                object-fit: cover;
                margin-bottom: 5px;
                page-break-inside: avoid;
            }
            
            .student-id {
                font-weight: bold;
                font-size: 11pt;
                color: #000;
                page-break-after: avoid;
            }
            
            .info-section {
                flex: 1;
                page-break-inside: avoid;
            }
            
            .section-title {
                font-size: 12pt;
                font-weight: bold;
                color: #000;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin: 12px 0 8px 0;
                page-break-after: avoid;
            }
            
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 12px;
                font-size: 10pt;
                page-break-inside: avoid;
            }
            
            .info-table th {
                background-color: #f0f0f0;
                border: 1px solid #000;
                padding: 6px 8px;
                text-align: left;
                font-weight: bold;
                width: 35%;
                page-break-inside: avoid;
            }
            
            .info-table td {
                border: 1px solid #000;
                padding: 6px 8px;
                width: 65%;
                page-break-inside: avoid;
            }
            
            .subjects-section {
                page-break-inside: auto;
                page-break-before: auto;
            }
            
            .category-title {
                font-weight: bold;
                color: #000;
                margin: 8px 0 4px 0;
                font-size: 10pt;
                background-color: #f8f8f8;
                padding: 4px 6px;
                page-break-after: avoid;
            }
            
            .subjects-table {
                width: 100%;
                border-collapse: collapse;
                margin: 5px 0 12px 0;
                font-size: 9pt;
                page-break-inside: avoid;
            }
            
            .subjects-table th {
                background-color: #f0f0f0;
                border: 1px solid #000;
                padding: 4px 6px;
                text-align: left;
                font-weight: bold;
                page-break-inside: avoid;
            }
            
            .subjects-table td {
                border: 1px solid #000;
                padding: 4px 6px;
                page-break-inside: avoid;
            }
            
            .signature-section {
                margin-top: 20px;
                display: flex;
                justify-content: space-between;
                page-break-inside: avoid;
                page-break-before: always;
            }
            
            .signature-box {
                width: 180px;
                text-align: center;
                page-break-inside: avoid;
            }
            
            .signature-line {
                border-top: 1px solid #000;
                margin-top: 30px;
                padding-top: 3px;
                font-size: 9pt;
                page-break-inside: avoid;
            }
            
            .footer {
                text-align: center;
                margin-top: 15px;
                font-size: 8pt;
                color: #666;
                border-top: 1px solid #ccc;
                padding-top: 8px;
                page-break-before: avoid;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            .avoid-break {
                page-break-inside: avoid;
            }
            
            .keep-together {
                page-break-inside: avoid;
                page-break-after: avoid;
            }
        }
        
        /* Print Preview Styles (Screen) */
        .print-content {
            background: white;
            padding: 30px;
            max-width: 8.3in;
            margin: 0 auto;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .school-header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 15px;
        }
        
        .school-name {
            font-size: 28px;
            font-weight: bold;
            color: var(--primary);
            margin: 0;
            line-height: 1.2;
        }
        
        .school-address {
            font-size: 14px;
            color: #666;
            margin: 8px 0;
        }
        
        .document-title {
            font-size: 22px;
            font-weight: bold;
            color: #000;
            text-align: center;
            margin: 20px 0;
            text-decoration: underline;
        }
        
        .student-info-container {
            display: flex;
            margin-bottom: 25px;
        }
        
        .photo-container {
            flex: 0 0 2in;
            margin-right: 25px;
            text-align: center;
        }
        
        .print-student-photo {
            width: 1.5in;
            height: 1.8in;
            border: 3px solid #ddd;
            object-fit: cover;
            margin-bottom: 10px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #666;
        }
        
        .student-id {
            font-weight: bold;
            font-size: 14px;
            color: #000;
        }
        
        .info-section {
            flex: 1;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
            padding-bottom: 8px;
            margin: 20px 0 12px 0;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        .info-table th {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 10px 12px;
            text-align: left;
            font-weight: bold;
            width: 35%;
            color: #495057;
        }
        
        .info-table td {
            border: 1px solid #dee2e6;
            padding: 10px 12px;
            width: 65%;
            color: #212529;
        }
        
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 15px 0;
            font-size: 12px;
        }
        
        .subjects-table th {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px 10px;
            text-align: left;
            font-weight: bold;
            color: #495057;
        }
        
        .subjects-table td {
            border: 1px solid #dee2e6;
            padding: 8px 10px;
            color: #212529;
        }
        
        .category-title {
            font-weight: bold;
            color: var(--primary);
            margin: 15px 0 8px 0;
            font-size: 14px;
            background: #f8f9fa;
            padding: 8px 12px;
            border-left: 4px solid var(--primary);
        }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }
        
        .signature-box {
            width: 200px;
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #212529;
            margin-top: 40px;
            padding-top: 8px;
            font-size: 12px;
            color: #666;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 11px;
            color: #666;
            border-top: 1px solid #dee2e6;
            padding-top: 12px;
        }
        
        /* Print Modal Actions */
        .print-actions {
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        /* Edit Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 1rem; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: white; border-radius: 10px; max-width: 900px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .modal-header { background: var(--primary); color: white; padding: 1.2rem 1.5rem; border-radius: 10px 10px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-size: 1.3rem; font-weight: 600; }
        .close-btn { background: none; border: none; color: white; font-size: 1.8rem; cursor: pointer; line-height: 1; opacity: 0.8; transition: opacity 0.2s; }
        .close-btn:hover { opacity: 1; }
        .modal-body { padding: 1.8rem; }
        .section-title { color: var(--primary); margin: 1.5rem 0 1rem 0; padding-bottom: 0.5rem; border-bottom: 2px solid var(--primary); font-size: 1.2rem; font-weight: 600; }
        .photo-preview { width: 150px; height: 150px; border-radius: 8px; object-fit: contain; border: 3px solid #dee2e6; box-shadow: 0 3px 10px rgba(0,0,0,0.1); }
        .subjects-container { margin-top: 1rem; }
        .category-group { margin-bottom: 1.5rem; background: #f8f9fa; padding: 1.2rem; border-radius: 8px; border-left: 5px solid var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .category-title { font-weight: 700; color: var(--primary); margin-bottom: 0.8rem; font-size: 1.1rem; }
        .subjects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.8rem; }
        .subject-checkbox { display: flex; align-items: center; gap: 0.8rem; padding: 0.5rem; background: white; border-radius: 4px; transition: background 0.2s; }
        .subject-checkbox:hover { background: #f0f0f0; }
        .subject-checkbox input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .subject-label { font-size: 0.95rem; color: #495057; cursor: pointer; }
        .subject-code { font-weight: 700; color: var(--secondary); }
        
        /* Delete Modal */
        .delete-modal-content { background: white; border-radius: 12px; max-width: 600px; width: 100%; overflow: hidden; box-shadow: 0 15px 40px rgba(0,0,0,0.25); max-height: fit-content; }
        .delete-modal-header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: .85rem; text-align: center; }
        .delete-modal-header h3 { margin: 0; font-size: 1.5rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .delete-modal-body { padding: 2rem; text-align: center; }
        .delete-icon { font-size: 2rem; color: #dc3545; margin-bottom: 0.95rem; }
        .delete-warning { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: .6rem; margin: .85rem 0; text-align: left; }
        .delete-warning h4 { color: #856404; margin: 0 0 0.5rem 0; font-size: 1rem; display: flex; align-items: center; gap: 8px; }
        .delete-warning ul { margin: 0.5rem 0 0 0.5rem; color: #856404; }
        .delete-warning li { margin-bottom: 0.3rem; font-size: 0.9rem; }
        .student-info-box { background: #f8f9fa; border-radius: 8px; padding: 0.8rem; margin: .08rem 0; border-left: 4px solid var(--danger); }
        .student-info-row { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
        .student-info-label { font-weight: 600; color: #495057; }
        .student-info-value { color: #212529; }
        .delete-actions { display: flex; gap: 0.5rem; justify-content: center; margin-top: 0.8rem; }
        .delete-btn-lg { padding: 0.8rem 2rem; font-size: 1rem; border-radius: 6px; }
        
        /* No Results */
        .no-results { text-align: center; padding: 3rem; color: #6c757d; }
        .no-results i { font-size: 3rem; margin-bottom: 1rem; color: #dee2e6; }
        
        /* Responsive - MATCHING ADMIN DASHBOARD */
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
            .sidebar:hover + .main-wrapper {
                margin-left: 280px;
                width: calc(100% - 280px);
            }
            .filter-grid { grid-template-columns: 1fr; }
            .delete-actions { flex-direction: column; }
            .delete-btn-lg { width: 100%; }
            .student-info-container {
                flex-direction: column;
            }
            .photo-container {
                margin-right: 0;
                margin-bottom: 20px;
                align-self: center;
            }
            .export-container {
                justify-content: flex-start;
            }
        }
        @media (max-width: 768px) {
            .action-buttons { flex-direction: column; }
            .modal-content, .delete-modal-content, .print-modal-content { max-width: 95%; }
            .modal-body { padding: 1.2rem; }
            .print-content {
                padding: 15px;
            }
            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar - MATCHING ADMIN DASHBOARD -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-school"></i>
            <span>School Admin</span>
        </div>
        <ul class="nav-menu">
            <!-- Teacher Management -->
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

            <!-- Student Management -->
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

            <!-- Classes & Stream -->
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

            <!-- Subjects -->
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

            <!-- Assessment -->
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-chart-bar"></i>
                    <span>Assessment</span>
                </a>
                <ul class="dropdown-menu">
                    <!-- O-Level -->
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
                    <!-- A-Level -->
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

            <!-- More -->
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
            <div class="role-tag"><?= ucfirst($user_role) ?></div>
        </header>
        
        <main class="main-content">
            <h2 class="page-title">
                View Learners
                <?php if ($user_role === 'admin'): ?>
                <a href="add_student.php" class="btn btn-info">
                    <i class="fas fa-plus"></i> Add New Student
                </a>
                <?php endif; ?>
            </h2>
            
            <?php if (isset($message)): ?>
                <div class="alert <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <!-- Filter Form -->
            <div class="filter-container">
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label for="level_id">Level</label>
                            <select name="level_id" id="level_id" class="form-control" onchange="this.form.submit()">
                                <option value="">All Levels</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['id'] ?>" <?= $level_id == $level['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="class_id">Class</label>
                            <select name="class_id" id="class_id" class="form-control" onchange="this.form.submit()" <?= empty($classes) ? 'disabled' : '' ?>>
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="stream_id">Stream</label>
                            <select name="stream_id" id="stream_id" class="form-control" <?= empty($streams) ? 'disabled' : '' ?> onchange="this.form.submit()">
                                <option value="">All Streams</option>
                                <?php foreach ($streams as $stream): ?>
                                    <option value="<?= $stream['id'] ?>" <?= $stream_id == $stream['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($stream['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control" onchange="this.form.submit()">
                                <option value="all" <?= $status == 'all' || $status == '' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="active" <?= $status == 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $status == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="graduated" <?= $status == 'graduated' ? 'selected' : '' ?>>Graduated</option>
                                <option value="transferred" <?= $status == 'transferred' ? 'selected' : '' ?>>Transferred</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="search-container">
                        <div class="form-group search-input">
                            <label for="search">Search</label>
                            <input type="text" name="search" id="search" class="form-control" 
                                   placeholder="Search by Student ID, Surname, or Other Names" 
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="form-group" style="align-self: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="students.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Results Info -->
            <div class="results-info">
                Found <?= count($students) ?> student<?= count($students) != 1 ? 's' : '' ?>
                <?php if ($level_id || $class_id || $stream_id || $search || ($status && $status !== 'all')): ?>
                    with current filters
                <?php endif; ?>
                <?php if ($user_role === 'teacher'): ?>
                    <span class="text-primary">(Viewing only your assigned classes)</span>
                <?php endif; ?>
            </div>
            
            <!-- Export Button -->
            <?php if (count($students) > 0): ?>
            <div class="export-container">
                <a href="students.php?export=csv&<?= http_build_query($_GET) ?>" class="btn btn-export">
                    <i class="fas fa-file-export"></i> Export to CSV
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Students Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <?php if (count($students) > 0): ?>
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th class="photo-cell">Photo</th>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Level</th>
                                    <th>Class</th>
                                    <th>Stream</th>
                                    <th>Sex</th>
                                    <th>Date of Birth</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr data-student-id="<?= $student['id'] ?>">
                                        <td class="photo-cell">
                                            <?php 
                                            $photo_path = $student['photo'] ?? '';
                                            if ($photo_path && file_exists($photo_path)): 
                                            ?>
                                                <img src="<?= $photo_path ?>" alt="Photo" class="student-photo" onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjUwIiBoZWlnaHQ9IjUwIiBmaWxsPSIjZGVlMmU2Ii8+PHRleHQgeD0iMjUiIHk9IjI1IiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTIiIGZpbGw9IiM2Yzc1N2QiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGR5PSIuM2VtIj5ObyBQaG90bzwvdGV4dD48L3N2Zz4=';">
                                            <?php else: ?>
                                                <div class="student-photo" style="background: #dee2e6; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-user" style="color: #6c757d;"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($student['student_id']) ?></strong></td>
                                        <td><?= htmlspecialchars($student['fullname']) ?></td>
                                        <td><?= htmlspecialchars($student['level_name']) ?></td>
                                        <td><?= htmlspecialchars($student['class_name']) ?></td>
                                        <td><?= htmlspecialchars($student['stream_name']) ?></td>
                                        <td><?= ucfirst($student['sex']) ?></td>
                                        <td><?= date('M j, Y', strtotime($student['date_of_birth'])) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $student['status'] ?>">
                                                <?= ucfirst($student['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn edit-btn" onclick="openEditModal(<?= htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8') ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="action-btn print-btn" onclick="openPrintModal(<?= $student['id'] ?>)">
                                                    <i class="fas fa-print"></i>
                                                </button>
                                                <?php if ($user_role === 'admin'): ?>
                                                <button class="action-btn delete-btn" onclick="confirmDelete(<?= $student['id'] ?>, '<?= htmlspecialchars(addslashes($student['student_id'])) ?>', '<?= htmlspecialchars(addslashes($student['fullname'])) ?>', '<?= htmlspecialchars(addslashes($student['level_name'])) ?>', '<?= htmlspecialchars(addslashes($student['class_name'])) ?>', '<?= htmlspecialchars(addslashes($student['stream_name'])) ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-results">
                            <i class="fas fa-user-graduate"></i>
                            <h3>No Students Found</h3>
                            <p>Try adjusting your filters or contact administrator to add students.</p>
                            <?php if ($user_role === 'admin'): ?>
                            <a href="add_student.php" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Add New Student
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        
        <!-- Footer Section - MATCHING ADMIN DASHBOARD -->
        <footer class="footer">
            <div class="footer-content">
                <div class="copyright">
                    <i class="far fa-copyright"></i>
                    <span><?= date('Y') ?> <?= htmlspecialchars($school_name) ?>. All rights reserved.</span>
                </div>
                <div class="footer-links">
                    <a href="privacy.php">Privacy Policy</a>
                    <a href="terms.php">Terms of Service</a>
                    <a href="contact.php">Contact Us</a>
                </div>
            </div>
        </footer>
    </div>
    
    <!-- Edit Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Student Profile</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="edit_student" value="1">
                <input type="hidden" name="student_id" id="edit_student_id">
                <input type="hidden" name="student_code" id="edit_student_code">
                <input type="hidden" name="current_photo" id="edit_current_photo">
                
                <div class="modal-body">
                    <div class="row" style="display: flex; margin-bottom: 1.5rem;">
                        <div style="flex: 0 0 150px; margin-right: 1.5rem;">
                            <div style="margin-bottom: 1rem;">
                                <img id="edit_photo_preview" src="" alt="Current Photo" class="photo-preview" style="width: 150px; height: 150px; margin-bottom: 0.5rem;">
                                <input type="file" name="photo" id="edit_photo" class="form-control" accept="image/jpeg,image/png" onchange="previewEditPhoto(event)">
                                <small class="text-muted">Max 2MB,JPG/PNG only</small>
                            </div>
                            <div class="form-group">
                                <label for="edit_student_id_display" class="form-label">Student ID</label>
                                <input type="text" class="form-control" id="edit_student_id_display" readonly>
                            </div>
                        </div>
                        <div style="flex: 1;">
                            <h4 class="section-title">Personal Information</h4>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem;">
                                <div class="form-group">
                                    <label for="edit_surname" class="required">Surname *</label>
                                    <input type="text" name="surname" id="edit_surname" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_other_names" class="required">Other Names *</label>
                                    <input type="text" name="other_names" id="edit_other_names" class="form-control" required>
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem;">
                                <div class="form-group">
                                    <label for="edit_sex" class="required">Sex *</label>
                                    <select name="sex" id="edit_sex" class="form-control" required>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_date_of_birth" class="required">Date of Birth *</label>
                                    <input type="date" name="date_of_birth" id="edit_date_of_birth" class="form-control" required max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="edit_status" class="required">Status *</label>
                                    <select name="status" id="edit_status" class="form-control" required>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="graduated">Graduated</option>
                                        <option value="transferred">Transferred</option>
                                    </select>
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                                <div class="form-group">
                                    <label for="edit_nationality" class="required">Nationality *</label>
                                    <select name="nationality" id="edit_nationality" class="form-control" required>
                                        <option value="">Select Nationality</option>
                                        <?php foreach ($east_african_countries as $country): ?>
                                            <option value="<?= $country ?>"><?= $country ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_home_district" class="required">Home District *</label>
                                    <select name="home_district" id="edit_home_district" class="form-control" required>
                                        <option value="">Select District</option>
                                        <?php foreach ($uganda_districts as $district): ?>
                                            <option value="<?= $district ?>"><?= $district ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <h4 class="section-title">Academic Information</h4>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="form-group">
                            <label for="edit_level_id" class="required">Level *</label>
                            <select name="level_id" id="edit_level_id" class="form-control" required onchange="loadClassesForEdit()">
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['id'] ?>"><?= htmlspecialchars($level['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_class_id" class="required">Class *</label>
                            <select name="class_id" id="edit_class_id" class="form-control" required onchange="loadStreamsForEdit()">
                                <option value="">Select Class</option>
                                <?php foreach ($all_classes as $class): ?>
                                    <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_stream_id" class="required">Stream *</label>
                            <select name="stream_id" id="edit_stream_id" class="form-control" required>
                                <option value="">Select Stream</option>
                            </select>
                        </div>
                    </div>
                    
                    <h4 class="section-title">Assigned Subjects</h4>
                    <div id="subjectsContainer" class="subjects-container">
                        <div class="alert info">
                            <i class="fas fa-info-circle"></i> Subjects will load after selecting a level.
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #dee2e6;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Print Modal -->
    <div class="print-modal-overlay" id="printModal">
        <div class="print-modal-content">
            <div class="print-modal-header">
                <h3>Print Student Profile</h3>
                <button class="print-close-btn" onclick="closeModal('printModal')">&times;</button>
            </div>
            <div class="print-modal-body" id="printModalBody">
                <!-- Print content will be loaded here via JavaScript -->
            </div>
            <div class="print-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('printModal')">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="button" class="btn btn-primary" onclick="printStudentProfile()">
                    <i class="fas fa-print"></i> Print Profile
                </button>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-content delete-modal-content">
            <div class="delete-modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Permanent Deletion</h3>
            </div>
            <form method="POST" action="" id="deleteForm">
                <div class="delete-modal-body">
                    <div class="delete-icon">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    
                    <h3 style="color: #dc3545; margin-bottom: 1rem;">Are you sure you want to permanently delete this student?</h3>
                    
                    <div class="student-info-box">
                        <div class="student-info-row">
                            <span class="student-info-label">Student ID:</span>
                            <span class="student-info-value" id="deleteStudentId"></span>
                        </div>
                        <div class="student-info-row">
                            <span class="student-info-label">Full Name:</span>
                            <span class="student-info-value" id="deleteStudentName"></span>
                        </div>
                        <div class="student-info-row">
                            <span class="student-info-label">Academic Info:</span>
                            <span class="student-info-value" id="deleteStudentAcademic"></span>
                        </div>
                    </div>
                    
                    <div class="delete-warning">
                        <h4><i class="fas fa-exclamation-circle"></i> This action cannot be undone!</h4>
                        <p style="color: #856404; margin: 0;">The following data will be permanently deleted:</p>
                        <ul>
                            <li>Student's personal information</li>
                            <li>Assigned subjects and academic records</li>
                            <li>Student photo (if exists)</li>
                            <li>All related marks and assessments</li>
                        </ul>
                    </div>
                    
                    <p style="color: #6c757d; font-size: 0.9rem; margin-top: 1rem;">
                        <i class="fas fa-lightbulb"></i> 
                        <strong>Note:</strong> Consider archiving instead if you might need this data later.
                    </p>
                    
                    <input type="hidden" name="delete_id" id="delete_id">
                    
                    <div class="delete-actions">
                        <button type="button" class="btn btn-warning delete-btn-lg" onclick="closeModal('deleteModal')">
                            <i class="fas fa-times"></i> Cancel Deletion
                        </button>
                        <button type="submit" class="btn btn-danger delete-btn-lg">
                            <i class="fas fa-trash"></i> Delete Permanently
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Initialize sidebar dropdowns
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
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        // Open print modal and load student data
        async function openPrintModal(studentId) {
            try {
                const response = await fetch(`students.php?get_student_data=1&student_id=${studentId}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const data = await response.json();
                
                if (data.error) {
                    alert(data.error);
                    return;
                }
                
                const student = data.student;
                const age = data.age;
                const subjectsByCategory = data.subjects_by_category;
                
                // Generate print content
                const printContent = generatePrintContent(student, age, subjectsByCategory);
                document.getElementById('printModalBody').innerHTML = printContent;
                
                // Open the modal
                openModal('printModal');
            } catch (error) {
                console.error('Error loading student data:', error);
                alert('Failed to load student data. Please try again.');
            }
        }
        
        // Generate print content HTML
        function generatePrintContent(student, age, subjectsByCategory) {
            const photoSrc = student.photo && student.photo.trim() !== '' ? student.photo : '';
            const totalSubjects = Object.values(subjectsByCategory).reduce((total, category) => total + category.length, 0);
            
            return `
                <div class="print-preview-container">
                    <div class="print-content" id="printContent">
                        <!-- First Page Content -->
                        <div class="first-page-content keep-together">
                            <!-- School Header -->
                            <div class="school-header avoid-break">
                                <h1 class="school-name">NSS SECONDARY SCHOOL</h1>
                                <p class="school-address">P.O. Box 12345, Kampala, Uganda | Tel: +256 123 456 789</p>
                                <p class="school-address">Email: info@nssschool.ac.ug | Website: www.nssschool.ac.ug</p>
                            </div>
                            
                            <!-- Document Title -->
                            <h2 class="document-title avoid-break">STUDENT PROFILE CARD</h2>
                            
                            <!-- Student Information Container -->
                            <div class="student-info-container keep-together">
                                <!-- Photo Section -->
                                <div class="photo-container avoid-break">
                                    ${photoSrc ? 
                                        `<img src="${photoSrc}" alt="Student Photo" class="print-student-photo" onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML='<div class=\\'print-student-photo\\' style=\\'display: flex; align-items: center; justify-content: center;\\'><span>No Photo Available</span></div>';" />` : 
                                        `<div class="print-student-photo" style="display: flex; align-items: center; justify-content: center;">
                                            <span>No Photo Available</span>
                                        </div>`
                                    }
                                    <div class="student-id avoid-break">ID: ${student.student_id}</div>
                                </div>
                                
                                <!-- Information Section -->
                                <div class="info-section">
                                    <!-- Personal Information -->
                                    <h3 class="section-title avoid-break">PERSONAL INFORMATION</h3>
                                    <table class="info-table avoid-break">
                                        <tr>
                                            <th>Full Name:</th>
                                            <td>${student.surname} ${student.other_names}</td>
                                        </tr>
                                        <tr>
                                            <th>Gender:</th>
                                            <td>${student.sex.charAt(0).toUpperCase() + student.sex.slice(1)}</td>
                                        </tr>
                                        <tr>
                                            <th>Date of Birth:</th>
                                            <td>${formatDate(student.date_of_birth)} (Age: ${age} years)</td>
                                        </tr>
                                        <tr>
                                            <th>Nationality:</th>
                                            <td>${student.nationality}</td>
                                        </tr>
                                        <tr>
                                            <th>Home District:</th>
                                            <td>${student.home_district}</td>
                                        </tr>
                                        <tr>
                                            <th>Student Status:</th>
                                            <td>${student.status.charAt(0).toUpperCase() + student.status.slice(1)}</td>
                                        </tr>
                                        <tr>
                                            <th>Status Type:</th>
                                            <td>${student.status_type}</td>
                                        </tr>
                                    </table>
                                    
                                    <!-- Academic Information -->
                                    <h3 class="section-title avoid-break">ACADEMIC INFORMATION</h3>
                                    <table class="info-table avoid-break">
                                        <tr>
                                            <th>Academic Year:</th>
                                            <td>${student.academic_year || 'Not specified'}</td>
                                        </tr>
                                        <tr>
                                            <th>Level:</th>
                                            <td>${student.level_name}</td>
                                        </tr>
                                        <tr>
                                            <th>Class:</th>
                                            <td>${student.class_name}</td>
                                        </tr>
                                        <tr>
                                            <th>Stream:</th>
                                            <td>${student.stream_name}</td>
                                        </tr>
                                        <tr>
                                            <th>Admission Number:</th>
                                            <td>${student.student_id}</td>
                                        </tr>
                                        <tr>
                                            <th>Admission Date:</th>
                                            <td>${formatDate(student.created_at)}</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Subjects Section -->
                        <div class="subjects-section">
                            <h3 class="section-title">ASSIGNED SUBJECTS (Total: ${totalSubjects})</h3>
                            ${Object.keys(subjectsByCategory).length > 0 ? 
                                Object.entries(subjectsByCategory).map(([category, subjects]) => `
                                    <div class="category-title">${category.charAt(0).toUpperCase() + category.slice(1)} SUBJECTS (${subjects.length})</div>
                                    <table class="subjects-table">
                                        <thead>
                                            <tr>
                                                <th width="15%">Code</th>
                                                <th width="60%">Subject Name</th>
                                                <th width="25%">Category</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${subjects.map(subject => `
                                                <tr>
                                                    <td><strong>${subject.code}</strong></td>
                                                    <td>${subject.name}</td>
                                                    <td>${subject.category}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                `).join('') : 
                                '<p style="text-align: center; font-style: italic; margin: 20px 0;">No subjects assigned.</p>'
                            }
                        </div>
                        
                        <!-- Signatures Section -->
                        <div class="signature-section">
                            <div class="signature-box">
                                <div class="signature-line"></div>
                                <p>Student's Signature</p>
                                <p>Date: __________________</p>
                            </div>
                            <div class="signature-box">
                                <div class="signature-line"></div>
                                <p>Class Teacher's Signature</p>
                                <p>Date: __________________</p>
                            </div>
                            <div class="signature-box">
                                <div class="signature-line"></div>
                                <p>Head Teacher's Signature</p>
                                <p>Date: __________________</p>
                            </div>
                        </div>
                        
                        <!-- Footer -->
                        <div class="footer">
                            <p>Generated on: ${formatDate(new Date().toISOString())} | NSS Secondary School Student Management System</p>
                            <p>This document is computer-generated and does not require a signature.</p>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Format date function
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
        
        // Print student profile
        function printStudentProfile() {
            const printContent = document.getElementById('printContent');
            const originalContents = document.body.innerHTML;
            
            document.body.innerHTML = printContent.outerHTML;
            window.print();
            document.body.innerHTML = originalContents;
            location.reload();
        }
        
        // Edit modal functions
        function openEditModal(student) {
            console.log('Opening edit modal for student:', student);
            
            // Populate basic fields
            document.getElementById('edit_student_id').value = student.id;
            document.getElementById('edit_student_code').value = student.student_id;
            document.getElementById('edit_student_id_display').value = student.student_id;
            document.getElementById('edit_surname').value = student.surname || '';
            document.getElementById('edit_other_names').value = student.other_names || '';
            document.getElementById('edit_sex').value = student.sex || 'male';
            document.getElementById('edit_date_of_birth').value = student.date_of_birth || '';
            document.getElementById('edit_nationality').value = student.nationality || '';
            document.getElementById('edit_home_district').value = student.home_district || '';
            document.getElementById('edit_level_id').value = student.level_id || '';
            document.getElementById('edit_class_id').value = student.class_id || '';
            document.getElementById('edit_status').value = student.status || 'active';
            
            // Handle photo
            const photoPreview = document.getElementById('edit_photo_preview');
            if (student.photo && student.photo.trim() !== '') {
                photoPreview.src = student.photo;
                document.getElementById('edit_current_photo').value = student.photo;
            } else {
                photoPreview.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2RlZTJlNiIvPjx0ZXh0IHg9Ijc1IiB5PSI3NSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmaWxsPSIjNmM3NTdkIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkeT0iLjNlbSI+Tm8gUGhvdG88L3RleHQ+PC9zdmc+';
                document.getElementById('edit_current_photo').value = '';
            }
            
            // Load streams for the selected class
            if (student.class_id) {
                loadStreamsForEdit(student.class_id, student.stream_id);
            }
            
            // Load subjects for the student
            if (student.level_id && student.id) {
                loadSubjectsForStudent(student.level_id, student.id);
            } else {
                document.getElementById('subjectsContainer').innerHTML = `
                    <div class="alert info">
                        <i class="fas fa-info-circle"></i> Select a level to load subjects.
                    </div>
                `;
            }
            
            // Open modal
            openModal('editModal');
        }
        
        function confirmDelete(studentId, studentIdDisplay, studentName, levelName, className, streamName) {
            document.getElementById('delete_id').value = studentId;
            document.getElementById('deleteStudentId').textContent = studentIdDisplay;
            document.getElementById('deleteStudentName').textContent = studentName;
            document.getElementById('deleteStudentAcademic').textContent = `${levelName} - ${className} (${streamName})`;
            openModal('deleteModal');
        }
        
        // Load streams for edit modal
        async function loadStreamsForEdit(classId = null, selectedStreamId = null) {
            const streamSelect = document.getElementById('edit_stream_id');
            
            if (!classId) {
                classId = document.getElementById('edit_class_id').value;
            }
            
            if (!classId) {
                streamSelect.innerHTML = '<option value="">Select Stream</option>';
                return;
            }
            
            try {
                const response = await fetch(`students.php?ajax_streams=1&class_id=${classId}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const streams = await response.json();
                
                let options = '<option value="">Select Stream</option>';
                streams.forEach(stream => {
                    const selected = stream.id == selectedStreamId ? 'selected' : '';
                    options += `<option value="${stream.id}" ${selected}>${stream.name}</option>`;
                });
                
                streamSelect.innerHTML = options;
            } catch (error) {
                console.error('Error loading streams:', error);
                streamSelect.innerHTML = '<option value="">Error loading streams</option>';
            }
        }
        
        // Load subjects for a student
        async function loadSubjectsForStudent(levelId, studentId) {
            const container = document.getElementById('subjectsContainer');
            
            if (!levelId || !studentId) {
                container.innerHTML = '<div class="alert info">Please select a level first.</div>';
                return;
            }
            
            try {
                const subjectsResponse = await fetch(`students.php?ajax_subjects=1&level_id=${levelId}`);
                if (!subjectsResponse.ok) throw new Error('Failed to load subjects');
                const allSubjects = await subjectsResponse.json();
                
                const assignedResponse = await fetch(`students.php?ajax_student_subjects=1&student_id=${studentId}`);
                if (!assignedResponse.ok) throw new Error('Failed to load assigned subjects');
                const assignedSubjects = await assignedResponse.json();
                
                const subjectsByCategory = {};
                allSubjects.forEach(subject => {
                    if (!subjectsByCategory[subject.category]) {
                        subjectsByCategory[subject.category] = [];
                    }
                    
                    const isAssigned = assignedSubjects.includes(parseInt(subject.id));
                    
                    subjectsByCategory[subject.category].push({
                        id: subject.id,
                        code: subject.code,
                        name: subject.name,
                        assigned: isAssigned
                    });
                });
                
                let html = '';
                for (const [category, subjects] of Object.entries(subjectsByCategory)) {
                    if (subjects.length > 0) {
                        html += `
                            <div class="category-group">
                                <div class="category-title">${category.charAt(0).toUpperCase() + category.slice(1)} Subjects</div>
                                <div class="subjects-grid">
                        `;
                        
                        subjects.forEach(subject => {
                            const checked = subject.assigned ? 'checked' : '';
                            html += `
                                <div class="subject-checkbox">
                                    <input type="checkbox" name="subjects[]" id="subject_${subject.id}" value="${subject.id}" ${checked}>
                                    <label for="subject_${subject.id}" class="subject-label">
                                        <span class="subject-code">${subject.code}</span> - ${subject.name}
                                    </label>
                                </div>
                            `;
                        });
                        
                        html += `
                                </div>
                            </div>
                        `;
                    }
                }
                
                if (html === '') {
                    html = '<div class="alert info">No subjects found for this level.</div>';
                }
                
                container.innerHTML = html;
                
            } catch (error) {
                console.error('Error loading subjects:', error);
                container.innerHTML = `
                    <div class="alert error">
                        <i class="fas fa-exclamation-circle"></i> Error loading subjects. Please try again.
                    </div>
                `;
            }
        }
        
        function loadClassesForEdit() {
            const levelId = document.getElementById('edit_level_id').value;
            const studentId = document.getElementById('edit_student_id').value;
            
            if (levelId && studentId) {
                loadSubjectsForStudent(levelId, studentId);
            } else {
                document.getElementById('subjectsContainer').innerHTML = `
                    <div class="alert info">
                        <i class="fas fa-info-circle"></i> Select a level to load subjects.
                    </div>
                `;
            }
        }
        
        function previewEditPhoto(event) {
            const input = event.target;
            const preview = document.getElementById('edit_photo_preview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        document.getElementById('editForm')?.addEventListener('submit', function(e) {
            const streamSelect = document.getElementById('edit_stream_id');
            if (!streamSelect.value) {
                e.preventDefault();
                alert('Please select a stream for the student.');
                streamSelect.focus();
                return false;
            }
            
            const subjects = document.querySelectorAll('input[name="subjects[]"]:checked');
            if (subjects.length === 0) {
                e.preventDefault();
                alert('Please select at least one subject for the student.');
                return false;
            }
            
            return true;
        });
        
        document.querySelectorAll('.modal-overlay, .print-modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            });
        });
        
        document.getElementById('deleteForm')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>