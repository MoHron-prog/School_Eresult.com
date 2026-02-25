<?php
// bulk_report_card_template.php
// This template is used for each student in the bulk report
// A4 Portrait: 8.27in × 11.69in with 0.5in margins
// Matches report_card_template.php styling exactly
?>
<div class="report-card" style="
    width: 7.27in;
    background: white;
    border: 2px solid #000;
    padding: 0.15in;
    font-family: 'Century Gothic', 'Segoe UI', sans-serif;
    font-size: 11pt;
    line-height: 1.25;
    color: #000;
    page-break-inside: avoid;
    break-inside: avoid;
">
    <!-- School Header -->
    <div class="school-header" style="display: flex; align-items: center; margin-bottom: 0.1in; border-bottom: 2px solid #000; padding-bottom: 0.08in;">
        <div class="school-logo-container" style="flex: 0 0 0.8in; margin-right: 0.15in;">
            <?php if (!empty($school_info['school_logo'])): ?>
                <img src="<?= htmlspecialchars($school_info['school_logo']) ?>" alt="School Logo" class="school-logo" style="width: 100%; max-width: 1.5in; height: auto; border: none;">
            <?php else: ?>
                <div style="width: 0.8in; height: 0.8in; border: 1px solid #000; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 9pt;">Logo</span>
                </div>
            <?php endif; ?>
        </div>
        <div class="school-details" style="flex: 1; text-align: center; margin-right: 0.6in;">
            <h1 style="font-size: 14pt; margin: 0 0 0.03in 0; color: #000; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;">
                <?= htmlspecialchars($school_info['school_name'] ?? 'NAPAK SEED SECONDARY SCHOOL') ?>
            </h1>
            <p class="school-address" style="margin: 0.02in 0; font-size: 9pt;">
                <?= htmlspecialchars($school_info['address'] ?? 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward Napak Town Council') ?>
            </p>
            <p class="school-contact" style="margin: 0.02in 0; font-size: 8.5pt;">
                Email: <?= htmlspecialchars($school_info['email'] ?? 'napakseed@gmail.com') ?> |
                Tel: <?= htmlspecialchars($school_info['phone'] ?? '0200 912 924/0770 880 274') ?>
            </p>
            <p class="school-motto" style="font-style: italic; margin: 0.03in 0; font-weight: bold; font-size: 10pt;">
                "<?= htmlspecialchars($school_info['motto'] ?? 'Achieving excellence together') ?>"
            </p>
        </div>
    </div>

    <!-- Report Title -->
    <div class="report-title" style="text-align: center; font-size: 12pt; font-weight: bold; color: #000; margin: 0.1in 0 0.08in 0; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #000; padding-bottom: 0.05in;">
        <?= ($level_id == 1) ? 'O-LEVEL REPORT CARD' : 'ADVANCED LEVEL REPORT CARD' ?>
    </div>

    <!-- Student Information -->
    <div class="student-info" style="margin: 0.1in 0; padding: 0.1in; border: 1px solid #000; background: #fff; display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.08in;">
        <div class="info-item" style="display: flex; flex-direction: column;">
            <span class="info-label" style="font-size: 9pt; font-weight: bold; color: #000;">STUDENT NAME:</span>
            <span class="info-value" style="font-size: 10pt; font-weight: normal; border-bottom: 1px dotted #999; padding: 0.02in 0;">
                <?= htmlspecialchars($report['student']['surname'] . ' ' . $report['student']['other_names']) ?>
            </span>
        </div>
        <div class="info-item" style="display: flex; flex-direction: column;">
            <span class="info-label" style="font-size: 9pt; font-weight: bold; color: #000;">SEX:</span>
            <span class="info-value" style="font-size: 10pt; font-weight: normal; border-bottom: 1px dotted #999; padding: 0.02in 0;">
                <?= ucfirst($report['student']['sex']) ?>
            </span>
        </div>
        <div class="info-item" style="display: flex; flex-direction: column;">
            <span class="info-label" style="font-size: 9pt; font-weight: bold; color: #000;">CLASS:</span>
            <span class="info-value" style="font-size: 10pt; font-weight: normal; border-bottom: 1px dotted #999; padding: 0.02in 0;">
                <?= htmlspecialchars($report['student']['class_name']) ?>
            </span>
        </div>
        <div class="info-item" style="display: flex; flex-direction: column;">
            <span class="info-label" style="font-size: 9pt; font-weight: bold; color: #000;">STREAM:</span>
            <span class="info-value" style="font-size: 10pt; font-weight: normal; border-bottom: 1px dotted #999; padding: 0.02in 0;">
                <?= htmlspecialchars($report['student']['stream_name']) ?>
            </span>
        </div>
        <div class="info-item" style="display: flex; flex-direction: column;">
            <span class="info-label" style="font-size: 9pt; font-weight: bold; color: #000;">TERM:</span>
            <span class="info-value" style="font-size: 10pt; font-weight: normal; border-bottom: 1px dotted #999; padding: 0.02in 0;">
                <?= htmlspecialchars($report['term_name']) ?>
            </span>
        </div>
        <div class="info-item" style="display: flex; flex-direction: column;">
            <span class="info-label" style="font-size: 9pt; font-weight: bold; color: #000;">YEAR:</span>
            <span class="info-value" style="font-size: 10pt; font-weight: normal; border-bottom: 1px dotted #999; padding: 0.02in 0;">
                <?= htmlspecialchars($report['student']['year_name']) ?>
            </span>
        </div>
    </div>

    <!-- Continuous Assessment & Summative Assessment Title -->
    <div style="font-weight: bold; text-align: center; margin: 0.05in 0; font-size: 10pt;">
        CONTINUOUS ASSESSMENT & SUMMATIVE ASSESSMENT
    </div>

    <?php if ($level_id == 1): ?>
        <!-- O-Level Report Card Format -->
        <table class="marks-table" style="width: 100%; border-collapse: collapse; margin: 0.1in 0; font-size: 9pt; border: 1px solid #000;">
            <thead>
                <tr>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: left; font-weight: bold; border: 1px solid #000; font-size: 9pt; padding-left: 0.08in;">SUBJECT NAME</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">CA (20%)</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">EoC (80%)</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">TOTAL (100%)</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">GRADE</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">ACHIEVEMENT LEVEL</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">TEACHER'S INITIALS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['marks'] as $subject_data): ?>
                    <tr>
                        <td class="subject-name" style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: left; font-size: 9pt; font-weight: 500; padding-left: 0.08in;">
                            <?= htmlspecialchars($subject_data['subject']['name']) ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['ca'] !== '' ? $subject_data['ca'] : '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['eoc_score'] !== '' ? $subject_data['eoc_score'] : '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['total'] !== '' ? $subject_data['total'] : '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['grade'] ?: '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['achievement_level'] ?: '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['teacher_initials'] ?: '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- RESULT -->
        <div class="result-section" style="margin: 0.1in 0; font-weight: bold; font-size: 12pt;">
            RESULT: <?= $report['result'] !== null ? $report['result'] : '-' ?>
        </div>
    <?php else: ?>
        <!-- A-Level Report Card Format -->
        <table class="marks-table" style="width: 100%; border-collapse: collapse; margin: 0.1in 0; font-size: 9pt; border: 1px solid #000;">
            <thead>
                <tr>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: left; font-weight: bold; border: 1px solid #000; font-size: 9pt; padding-left: 0.08in;">SUBJECTS</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">MARKS (100%)</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">SUBJECT GRADE</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">REMARKS</th>
                    <th style="background: #fff; color: #000; padding: 0.05in 0.03in; text-align: center; font-weight: bold; border: 1px solid #000; font-size: 9pt;">TEACHERS' INITIALS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['marks'] as $subject_data): ?>
                    <tr>
                        <td class="subject-name" style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: left; font-size: 9pt; font-weight: 500; padding-left: 0.08in;">
                            <?= htmlspecialchars($subject_data['subject']['name']) ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['total'] !== '' ? $subject_data['total'] : '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['grade'] ?: '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['achievement_level'] ?: '-' ?>
                        </td>
                        <td style="padding: 0.04in 0.03in; border: 1px solid #000; text-align: center; font-size: 9pt;">
                            <?= $subject_data['teacher_initials'] ?: '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- A-Level Points Summary -->
        <table class="points-table" style="width: 100%; border-collapse: collapse; margin: 0.08in 0; border: 1px solid #000;">
            <thead>
                <tr>
                    <th style="background: #fff; color: #000; padding: 0.04in 0.05in; border: 1px solid #000; text-align: center; font-weight: bold; font-size: 10pt;">PRINCIPAL</th>
                    <th style="background: #fff; color: #000; padding: 0.04in 0.05in; border: 1px solid #000; text-align: center; font-weight: bold; font-size: 10pt;">SUBSIDIARY</th>
                    <th style="background: #fff; color: #000; padding: 0.04in 0.05in; border: 1px solid #000; text-align: center; font-weight: bold; font-size: 10pt;">TOTAL POINTS</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="border: 1px solid #000; padding: 0.04in 0.05in; text-align: center; width: 33.33%; font-size: 12pt; font-weight: bold;">
                        <?= $report['principal_count'] ?>
                    </td>
                    <td style="border: 1px solid #000; padding: 0.04in 0.05in; text-align: center; width: 33.33%; font-size: 12pt; font-weight: bold;">
                        <?= $report['subsidiary_count'] ?>
                    </td>
                    <td style="border: 1px solid #000; padding: 0.04in 0.05in; text-align: center; width: 33.33%; font-size: 12pt; font-weight: bold;">
                        <?= $report['total_points_sum'] ?>
                    </td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>


    

    <!-- Comments Section - From academic_comments table -->
    <div class="comments-section">
        <!-- Class Teacher Comment -->
        <div class="comment-row">
            <div class="comment-label-cell">CLASS TEACHER'S COMMENT</div>
            <div class="comment-content-cell">
                <?= htmlspecialchars($class_teacher_comment ?: 'No class teacher comment available for this performance range.') ?>
            </div>
        </div>

        <!-- Head Teacher Comment -->
        <div class="comment-row">
            <div class="comment-label-cell">HEAD TEACHER'S COMMENT</div>
            <div class="comment-content-cell">
                <?= htmlspecialchars($head_teacher_comment ?: 'No head teacher comment available for this performance range.') ?>
            </div>
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
    <div class="grading-scale" style="margin-top: 0.1in; padding: 0.08in; border: 1px solid #000; background: #fff; font-size: 8.5pt; text-align: center;">
        <?php if ($level_id == 1): ?>
            <!-- O-Level Grading Scale -->
            <table style="width: 100%; border-collapse: collapse; margin-top: 0.05in;">
                <thead>
                    <tr>
                        <td class="no-top-left-border" style="border-top: none !important; border-left: none !important;"></td>
                        <td colspan="6"><strong>GRADING SCALE</strong></td>
                    </tr>
                    <tr>
                        <th class="text-align-left" style="text-align: left !important; width: 140px; background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">SCORE</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">80-100</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">70-79</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">60-69</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">50-59</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">0-49</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-align-left" style="text-align: left !important; width: 140px; padding: 0.03in; border: 1px solid #000; font-size: 8.5pt;"><strong>GRADE</strong></td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">A</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">B</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">C</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">D</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">E</td>
                    </tr>
                    <tr>
                        <td class="text-align-left" style="text-align: left !important; width: 140px; padding: 0.03in; border: 1px solid #000; font-size: 8.5pt;"><strong>ACHIEVEMENT LEVEL</strong></td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">Exceptional</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">Outstanding</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">Satisfactory</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">Basic</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">Elementary</td>
                    </tr>
                </tbody>
            </table>
        <?php else: ?>
            <!-- A-Level Grading Scale -->
            <table style="width: 100%; border-collapse: collapse; margin-top: 0.05in;">
                <thead>
                    <tr>
                        <td class="no-top-left-border" style="border-top: none !important; border-left: none !important;"></td>
                        <td colspan="9"><strong>GRADING SCALE</strong></td>
                    </tr>
                    <tr>
                        <th class="text-align-left" style="text-align: left !important; width: 140px; background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">SCORE RANGE</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">85-100</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">80-84</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">70-79</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">65-69</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">60-64</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">50-59</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">40-49</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">35-39</th>
                        <th style="background: #fff; color: #000; padding: 0.03in; border: 1px solid #000; font-weight: bold; font-size: 8.5pt;">0-34</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-align-left" style="text-align: left !important; width: 140px; padding: 0.03in; border: 1px solid #000; font-size: 8.5pt;"><strong>GRADE</strong></td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">D1</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">D2</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">C3</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">C4</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">C5</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">C6</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">P7</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">P8</td>
                        <td style="padding: 0.03in; border: 1px solid #000; text-align: center; font-size: 8.5pt;">F9</td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Footer Section -->
    <div class="footer-section" style="margin-top: 0.1in; border: 1px solid #000; width: 100%; table-layout: fixed;">
        <div class="footer-row" style="display: flex; width: 100%; border-bottom: 1px solid #000;">
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-label" style="font-weight: bold; color: #000; display: block; text-align: center;">STUDENT PAY CODE</span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-label" style="font-weight: bold; color: #000; display: block; text-align: center;">OUTSTANDING FEES BAL:</span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-label" style="font-weight: bold; color: #000; display: block; text-align: center;">NEXT TERM FEES TOTAL</span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-label" style="font-weight: bold; color: #000; display: block; text-align: center;">DATE OF ISSUE:</span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: none; text-align: center; font-size: 8.5pt;">
                <span class="footer-label" style="font-weight: bold; color: #000; display: block; text-align: center;">NEXT TERM BEGINS:</span>
            </div>
        </div>
        <div class="footer-row" style="display: flex; width: 100%;">
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-value" style="display: block; text-align: center; margin-top: 0.02in; min-height: 0.15in;"></span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-value" style="display: block; text-align: center; margin-top: 0.02in; min-height: 0.15in;"></span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-value" style="display: block; text-align: center; margin-top: 0.02in; min-height: 0.15in;"></span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: 1px solid #000; text-align: center; font-size: 8.5pt;">
                <span class="footer-value" style="display: block; text-align: center; margin-top: 0.02in; min-height: 0.15in;"><?= htmlspecialchars($report['date_of_issue']) ?></span>
            </div>
            <div class="footer-cell" style="flex: 1; min-width: 0; padding: 0.06in 0.04in; border-right: none; text-align: center; font-size: 8.5pt;">
                <span class="footer-value" style="display: block; text-align: center; margin-top: 0.02in; min-height: 0.15in;"><?= htmlspecialchars($report['next_term_begins']) ?></span>
            </div>
        </div>
    </div>

    <!-- Invalid stamp at bottom -->
    <div class="invalid-stamp" style="text-align: center; margin-top: 0.1in; font-size: 8.5pt; font-style: italic; color: #000;">
        This report card is invalid without school stamp
    </div>
</div>