<?php
// bulk_report_card_template.php
// This template is used for each student in the bulk report
// A4 Portrait: 8.27in × 11.69in with 0.5in margins
// Matches report_card_template.php styling exactly
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card</title>
    <style>
        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: white;
            font-family: 'Century Gothic', 'Segoe UI', sans-serif;
        }

        /* Report Card Container */
        .report-card {
            width: 7.27in;
            background: white;
            border: 2px solid #000;
            padding: 0.15in;
            font-family: 'Century Gothic', 'Segoe UI', sans-serif;
            font-size: 11pt;
            line-height: 1.0;
            color: #000;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* School Header */
        .school-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.1in;
            border-bottom: 2px solid #000;
            padding-bottom: 0.08in;
        }

        .school-logo-container {
            flex: 0 0 0.8in;
            margin-right: 0.15in;
        }

        .school-logo {
            width: 100%;
            max-width: 1.5in;
            height: auto;
            border: none;
        }

        .logo-placeholder {
            width: 0.8in;
            height: 0.8in;
            border: 1px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-placeholder span {
            font-size: 9pt;
        }

        .school-details {
            flex: 1;
            text-align: center;
            margin-right: 0.6in;
        }

        .school-details h1 {
            font-size: 14pt;
            margin: 0 0 0.03in 0;
            color: #000;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .school-address {
            margin: 0.02in 0;
            font-size: 9pt;
        }

        .school-contact {
            margin: 0.02in 0;
            font-size: 8.5pt;
        }

        .school-motto {
            font-style: italic;
            margin: 0.03in 0;
            font-weight: bold;
            font-size: 10pt;
        }

        /* Report Title */
        .report-title {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            color: #000;
            margin: 0.1in 0 0.08in 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #000;
            padding-bottom: 0.05in;
        }

        /* Student Information */
        .student-info {
            margin: 0.1in 0;
            padding: 0.1in;
            border: 1px solid #000;
            background: #fff;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.08in;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 9pt;
            font-weight: bold;
            color: #000;
        }

        .info-value {
            font-size: 10pt;
            font-weight: normal;
            border-bottom: 1px dotted #999;
            padding: 0.02in 0;
        }

        /* Section Title */
        .section-title {
            font-weight: bold;
            text-align: center;
            margin: 0.05in 0;
            font-size: 10pt;
        }

        /* Tables */
        .marks-table,
        .points-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.1in 0;
            font-size: 9pt;
            border: 1px solid #000;
        }

        .marks-table th,
        .points-table th {
            background: #fff;
            color: #000;
            padding: 0.05in 0.03in;
            font-weight: bold;
            border: 1px solid #000;
            font-size: 9pt;
        }

        .marks-table th:first-child,
        .points-table th:first-child {
            padding-left: 0.08in;
            text-align: left;
        }

        .marks-table th:not(:first-child) {
            text-align: center;
        }

        .marks-table td {
            padding: 0.04in 0.03in;
            border: 1px solid #000;
            font-size: 9pt;
        }

        .marks-table td.subject-name {
            text-align: left;
            font-weight: 500;
            padding-left: 0.08in;
        }

        .marks-table td:not(.subject-name) {
            text-align: center;
        }

        .points-table td {
            border: 1px solid #000;
            padding: 0.04in 0.05in;
            text-align: center;
            width: 33.33%;
            font-size: 12pt;
            font-weight: bold;
        }

        /* Result Section */
        .result-section {
            margin: 0.1in 0;
            font-weight: bold;
            font-size: 12pt;
        }

        /* Comments Section */
        .comments-section {
            margin: 0.1in 0;
            border: 1px solid #000;
            width: 100%;
            table-layout: fixed;
        }

        .comment-row {
            display: flex;
            width: 100%;
            border-bottom: 1px solid #000;
        }

        .comment-row:last-child {
            border-bottom: none;
        }

        .comment-label-cell {
            flex: 0 0 2in;
            min-width: 0;
            padding: 0.08in 0.05in;
            border-right: 1px solid #000;
            font-weight: bold;
            color: #000;
            display: block;
            text-align: left;
        }

        .comment-content-cell {
            flex: 1;
            min-width: 0;
            padding: 0.08in 0.05in;
            text-align: left;
            font-size: 9pt;
        }

        /* Signatures */
        .signature-row {
            display: flex;
            width: 100%;
            margin: 0.1in 0;
            padding: 0.08in 0.05in;
            border: 1px solid #000;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }

        .signature-cell {
            flex: 1;
            min-width: 0;
            padding: 0.08in 0.05in;
        }

        .signature-cell:first-child {
            border-right: 1px solid #000;
        }

        .signature-label {
            font-weight: bold;
            color: #000;
            display: block;
            text-align: left;
        }

        .signature-line {
            display: block;
            border-bottom: 1px solid #000;
            margin-top: 0.08in;
            min-height: 0.15in;
        }

        /* Grading Scale */
        .grading-scale {
            margin-top: 0.1in;
            padding: 0.08in;
            border: 1px solid #000;
            background: #fff;
            font-size: 8.5pt;
            text-align: center;
        }

        .grading-scale table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.05in;
        }

        .grading-scale th,
        .grading-scale td {
            padding: 0.03in;
            border: 1px solid #000;
        }

        .grading-scale th {
            background: #fff;
            color: #000;
            font-weight: bold;
            font-size: 8.5pt;
        }

        .grading-scale td.text-align-left {
            text-align: left !important;
            width: 140px;
        }

        .grading-scale .no-top-left-border {
            border-top: none !important;
            border-left: none !important;
        }

        /* Footer Section */
        .footer-section {
            margin-top: 0.1in;
            border: 1px solid #000;
            width: 100%;
            table-layout: fixed;
        }

        .footer-row {
            display: flex;
            width: 100%;
            border-bottom: 1px solid #000;
        }

        .footer-row:last-child {
            border-bottom: none;
        }

        .footer-cell {
            flex: 1;
            min-width: 0;
            padding: 0.06in 0.04in;
            border-right: 1px solid #000;
            text-align: center;
            font-size: 8.5pt;
        }

        .footer-cell:last-child {
            border-right: none;
        }

        .footer-label {
            font-weight: bold;
            color: #000;
            display: block;
            text-align: center;
        }

        .footer-value {
            display: block;
            text-align: center;
            margin-top: 0.02in;
            min-height: 0.15in;
        }

        /* Invalid Stamp */
        .invalid-stamp {
            text-align: center;
            margin-top: 0.1in;
            font-size: 8.5pt;
            font-style: italic;
            color: #000;
        }

        /* Print-specific styles */
        @media print {
            body {
                background: white;
            }

            .report-card {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <div class="report-card">
        <!-- School Header -->
        <div class="school-header">
            <div class="school-logo-container">
                <?php if (!empty($school_info['school_logo'])): ?>
                    <img src="<?= htmlspecialchars($school_info['school_logo']) ?>" alt="School Logo" class="school-logo">
                <?php else: ?>
                    <div class="logo-placeholder">
                        <span>Logo</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="school-details">
                <h1><?= htmlspecialchars($school_info['school_name'] ?? 'NAPAK SEED SECONDARY SCHOOL') ?></h1>
                <p class="school-address"><?= htmlspecialchars($school_info['address'] ?? 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward Napak Town Council') ?></p>
                <p class="school-contact">Email: <?= htmlspecialchars($school_info['email'] ?? 'napakseed@gmail.com') ?> | Tel: <?= htmlspecialchars($school_info['phone'] ?? '0200 912 924/0770 880 274') ?></p>
                <p class="school-motto">"<?= htmlspecialchars($school_info['motto'] ?? 'Achieving excellence together') ?>"</p>
            </div>
        </div>

        <!-- Report Title -->
        <div class="report-title">
            <?= ($level_id == 1) ? 'O-LEVEL REPORT CARD' : 'ADVANCED LEVEL REPORT CARD' ?>
        </div>

        <!-- Student Information -->
        <div class="student-info">
            <div class="info-item">
                <span class="info-label">STUDENT NAME:</span>
                <span class="info-value"><?= htmlspecialchars($report['student']['surname'] . ' ' . $report['student']['other_names']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">SEX:</span>
                <span class="info-value"><?= ucfirst($report['student']['sex']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">CLASS:</span>
                <span class="info-value"><?= htmlspecialchars($report['student']['class_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">STREAM:</span>
                <span class="info-value"><?= htmlspecialchars($report['student']['stream_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">TERM:</span>
                <span class="info-value"><?= htmlspecialchars($report['term_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">YEAR:</span>
                <span class="info-value"><?= htmlspecialchars($report['student']['year_name']) ?></span>
            </div>
        </div>

        <!-- Continuous Assessment & Summative Assessment Title -->
        <div class="section-title">CONTINUOUS ASSESSMENT & SUMMATIVE ASSESSMENT</div>

        <?php if ($level_id == 1): ?>
            <!-- O-Level Report Card Format -->
            <table class="marks-table">
                <thead>
                    <tr>
                        <th>SUBJECT NAME</th>
                        <th>CA (20%)</th>
                        <th>EoC (80%)</th>
                        <th>TOTAL (100%)</th>
                        <th>GRADE</th>
                        <th>ACHIEVEMENT LEVEL</th>
                        <th>TEACHER'S INITIALS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['marks'] as $subject_data): ?>
                        <tr>
                            <td class="subject-name"><?= htmlspecialchars($subject_data['subject']['name']) ?></td>
                            <td><?= $subject_data['ca'] !== '' ? $subject_data['ca'] : '-' ?></td>
                            <td><?= $subject_data['eoc_score'] !== '' ? $subject_data['eoc_score'] : '-' ?></td>
                            <td><?= $subject_data['total'] !== '' ? $subject_data['total'] : '-' ?></td>
                            <td><?= $subject_data['grade'] ?: '-' ?></td>
                            <td><?= $subject_data['achievement_level'] ?: '-' ?></td>
                            <td><?= $subject_data['teacher_initials'] ?: '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- RESULT -->
            <div class="result-section">RESULT: <?= $report['result'] !== null ? $report['result'] : '-' ?></div>
        <?php else: ?>
            <!-- A-Level Report Card Format -->
            <table class="marks-table">
                <thead>
                    <tr>
                        <th>SUBJECTS</th>
                        <th>MARKS (100%)</th>
                        <th>SUBJECT GRADE</th>
                        <th>REMARKS</th>
                        <th>TEACHERS' INITIALS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['marks'] as $subject_data): ?>
                        <tr>
                            <td class="subject-name"><?= htmlspecialchars($subject_data['subject']['name']) ?></td>
                            <td><?= $subject_data['total'] !== '' ? $subject_data['total'] : '-' ?></td>
                            <td><?= $subject_data['grade'] ?: '-' ?></td>
                            <td><?= $subject_data['achievement_level'] ?: '-' ?></td>
                            <td><?= $subject_data['teacher_initials'] ?: '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- A-Level Points Summary -->
            <table class="points-table">
                <thead>
                    <tr>
                        <th>PRINCIPAL</th>
                        <th>SUBSIDIARY</th>
                        <th>TOTAL POINTS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= $report['principal_count'] ?></td>
                        <td><?= $report['subsidiary_count'] ?></td>
                        <td><?= $report['total_points_sum'] ?></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Comments Section - From academic_comments table -->
        <div class="comments-section">
            <div class="comment-row">
                <div class="comment-label-cell">CLASS TEACHER'S COMMENT</div>
                <div class="comment-content-cell"><?= htmlspecialchars($class_teacher_comment ?: 'No class teacher comment available for this performance range.') ?></div>
            </div>
            <div class="comment-row">
                <div class="comment-label-cell">HEAD TEACHER'S COMMENT</div>
                <div class="comment-content-cell"><?= htmlspecialchars($head_teacher_comment ?: 'No head teacher comment available for this performance range.') ?></div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signature-row">
            <div class="signature-cell">
                <span class="signature-label">CLASS TEACHER'S SIGNATURE:</span>
                <span class="signature-line"></span>
            </div>
            <div class="signature-cell">
                <span class="signature-label">HEAD TEACHER'S SIGNATURE:</span>
                <span class="signature-line"></span>
            </div>
        </div>

        <!-- Grading Scale -->
        <div class="grading-scale">
            <?php if ($level_id == 1): ?>
                <!-- O-Level Grading Scale -->
                <table>
                    <thead>
                        <tr>
                            <td class="no-top-left-border"></td>
                            <td colspan="6"><strong>GRADING SCALE</strong></td>
                        </tr>
                        <tr>
                            <th class="text-align-left">SCORE</th>
                            <th>80-100</th>
                            <th>70-79</th>
                            <th>60-69</th>
                            <th>50-59</th>
                            <th>0-49</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-align-left"><strong>GRADE</strong></td>
                            <td>A</td>
                            <td>B</td>
                            <td>C</td>
                            <td>D</td>
                            <td>E</td>
                        </tr>
                        <tr>
                            <td class="text-align-left"><strong>ACHIEVEMENT LEVEL</strong></td>
                            <td>Exceptional</td>
                            <td>Outstanding</td>
                            <td>Satisfactory</td>
                            <td>Basic</td>
                            <td>Elementary</td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <!-- A-Level Grading Scale -->
                <table>
                    <thead>
                        <tr>
                            <td class="no-top-left-border"></td>
                            <td colspan="9"><strong>GRADING SCALE</strong></td>
                        </tr>
                        <tr>
                            <th class="text-align-left">SCORE RANGE</th>
                            <th>85-100</th>
                            <th>80-84</th>
                            <th>70-79</th>
                            <th>65-69</th>
                            <th>60-64</th>
                            <th>50-59</th>
                            <th>40-49</th>
                            <th>35-39</th>
                            <th>0-34</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-align-left"><strong>GRADE</strong></td>
                            <td>D1</td>
                            <td>D2</td>
                            <td>C3</td>
                            <td>C4</td>
                            <td>C5</td>
                            <td>C6</td>
                            <td>P7</td>
                            <td>P8</td>
                            <td>F9</td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Footer Section -->
        <div class="footer-section">
            <div class="footer-row">
                <div class="footer-cell"><span class="footer-label">STUDENT PAY CODE</span></div>
                <div class="footer-cell"><span class="footer-label">OUTSTANDING FEES BAL:</span></div>
                <div class="footer-cell"><span class="footer-label">NEXT TERM FEES TOTAL</span></div>
                <div class="footer-cell"><span class="footer-label">DATE OF ISSUE:</span></div>
                <div class="footer-cell"><span class="footer-label">NEXT TERM BEGINS:</span></div>
            </div>
            <div class="footer-row">
                <div class="footer-cell"><span class="footer-value"></span></div>
                <div class="footer-cell"><span class="footer-value"></span></div>
                <div class="footer-cell"><span class="footer-value"></span></div>
                <div class="footer-cell"><span class="footer-value"><?= htmlspecialchars($report['date_of_issue']) ?></span></div>
                <div class="footer-cell"><span class="footer-value"><?= htmlspecialchars($report['next_term_begins']) ?></span></div>
            </div>
        </div>

        <!-- Invalid stamp at bottom -->
        <div class="invalid-stamp">This report card is invalid without school stamp</div>
    </div>
</body>

</html>