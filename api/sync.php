<?php
/**
 * نقطة استقبال المزامنة الرئيسية من أداة سكول برو (Python Tool Sync Receiver)
 * POST /api/sync.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/auth.php';

// يجب أن يكون الطلب POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, null, "الطريقة غير مسموحة. الرجاء إرسال طلب POST.", 405);
}

// التحقق من المصادقة
requireApiAuth();

// قراءة بيانات الطلب (JSON Body)
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    jsonResponse(false, null, "لم يتم إرسال أي بيانات في نص الطلب.", 400);
}

$payload = json_decode($rawInput, true);
if (!$payload || !is_array($payload)) {
    jsonResponse(false, null, "صيغة البيانات غير صالحة. يجب إرسال كائن JSON صالح.", 400);
}

$pdo = getDbConnection();

try {
    $pdo->beginTransaction();

    $syncTimestamp = $payload['sync_timestamp'] ?? date('c');
    $batchIndex = $payload['batch_index'] ?? 1;
    $totalBatches = $payload['total_batches'] ?? 1;

    // 1. تحديث بيانات المدرسة
    if (!empty($payload['school_info'])) {
        $si = $payload['school_info'];
        $stmtSchool = $pdo->prepare("
            INSERT INTO school_info (school_id, school_name, address, phone, city, manager_title, manager_name, email)
            VALUES (:id, :name, :address, :phone, :city, :man_title, :man_name, :email)
            ON CONFLICT(school_id) DO UPDATE SET
                school_name = excluded.school_name,
                address = excluded.address,
                phone = excluded.phone,
                city = excluded.city,
                manager_title = excluded.manager_title,
                manager_name = excluded.manager_name,
                email = excluded.email
        ");
        $stmtSchool->execute([
            ':id' => $si['school_id'] ?? '0076',
            ':name' => $si['school_name'] ?? 'مدارس مكة الأهلية الحديثة',
            ':address' => $si['address'] ?? '',
            ':phone' => $si['phone'] ?? '',
            ':city' => $si['city'] ?? '',
            ':man_title' => $si['manager_title'] ?? '',
            ':man_name' => $si['manager_name'] ?? '',
            ':email' => $si['email'] ?? ''
        ]);
    }

    // التأكد من وجود الفهرس الفريد
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_student_year_summary ON student_account_summary(student_id, academic_year);");

    // 2. معالجة حزمة الطلاب
    $students = $payload['students'] ?? [];
    $syncedStudentsCount = 0;
    $syncedTransCount = 0;
    $syncedGradesCount = 0;

    $stmtStudent = $pdo->prepare("
        INSERT INTO students (
            student_id, student_code, full_name, current_class_id, current_class_name,
            branch_id, branch_name, academic_year, gender, birth_date, birth_place,
            registration_date, phone1, phone2, mobile, address, guardian_name,
            guardian_job, mother_name, previous_school, status
        ) VALUES (
            :id, :code, :name, :class_id, :class_name,
            :branch_id, :branch_name, :academic_year, :gender, :birth_date, :birth_place,
            :reg_date, :phone1, :phone2, :mobile, :address, :g_name,
            :g_job, :mother_name, :prev_school, :status
        )
        ON CONFLICT(student_id) DO UPDATE SET
            student_code = excluded.student_code,
            full_name = excluded.full_name,
            current_class_id = excluded.current_class_id,
            current_class_name = excluded.current_class_name,
            branch_id = excluded.branch_id,
            branch_name = excluded.branch_name,
            academic_year = excluded.academic_year,
            gender = excluded.gender,
            phone1 = excluded.phone1,
            mobile = excluded.mobile,
            address = excluded.address,
            guardian_name = excluded.guardian_name,
            status = excluded.status
    ");

    $stmtAccSummary = $pdo->prepare("
        INSERT INTO student_account_summary (
            student_id, student_name, class_name, academic_year,
            total_fees, total_paid, remaining_balance, payment_status, last_payment_date
        ) VALUES (
            :st_id, :st_name, :class_name, :academic_year,
            :total_fees, :total_paid, :remaining, :status, :last_pay_date
        )
        ON CONFLICT(student_id, academic_year) DO UPDATE SET
            total_fees = excluded.total_fees,
            total_paid = excluded.total_paid,
            remaining_balance = excluded.remaining_balance,
            payment_status = excluded.payment_status,
            last_payment_date = excluded.last_payment_date
    ");

    $stmtDelTrans = $pdo->prepare("DELETE FROM financial_transactions WHERE student_id = ?");
    $stmtTrans = $pdo->prepare("
        INSERT INTO financial_transactions (
            trans_id, student_id, registration_code, trans_type, acc_type_code,
            fee_type_id, fee_type_name, amount, receipt_no, trans_date,
            due_date, academic_year, month_no, notes
        ) VALUES (
            :trans_id, :student_id, :registration_code, :trans_type, :acc_type_code,
            :fee_type_id, :fee_type_name, :amount, :receipt_no, :trans_date,
            :due_date, :academic_year, :month_no, :notes
        )
    ");

    $stmtDelGrades = $pdo->prepare("DELETE FROM student_grades WHERE student_id = ?");
    $stmtGrade = $pdo->prepare("
        INSERT INTO student_grades (
            student_id, registration_code, student_name, class_name,
            subject_id, subject_name, term_no, term_name, term_work_score,
            final_exam_score, total_score, max_score, grade_estimate, academic_year
        ) VALUES (
            :student_id, :registration_code, :student_name, :class_name,
            :subject_id, :subject_name, :term_no, :term_name, :term_work_score,
            :final_exam_score, :total_score, :max_score, :grade_estimate, :academic_year
        )
    ");

    foreach ($students as $item) {
        $st = $item['student_info'] ?? $item;
        $stId = $st['student_id'];

        // 1. بطاقة الطالب
        $stmtStudent->execute([
            ':id' => $stId,
            ':code' => $st['student_code'] ?? '',
            ':name' => $st['full_name'] ?? '',
            ':class_id' => $st['current_class_id'] ?? null,
            ':class_name' => $st['current_class_name'] ?? '',
            ':branch_id' => $st['branch_id'] ?? null,
            ':branch_name' => $st['branch_name'] ?? '',
            ':academic_year' => $st['academic_year'] ?? '',
            ':gender' => $st['gender'] ?? 'ذكر',
            ':birth_date' => $st['birth_date'] ?? '',
            ':birth_place' => $st['birth_place'] ?? '',
            ':reg_date' => $st['registration_date'] ?? '',
            ':phone1' => $st['phone1'] ?? '',
            ':phone2' => $st['phone2'] ?? '',
            ':mobile' => $st['mobile'] ?? '',
            ':address' => $st['address'] ?? '',
            ':g_name' => $st['guardian_name'] ?? '',
            ':g_job' => $st['guardian_job'] ?? '',
            ':mother_name' => $st['mother_name'] ?? '',
            ':prev_school' => $st['previous_school'] ?? '',
            ':status' => $st['status'] ?? 'مستمر'
        ]);
        $syncedStudentsCount++;

        // 2. كشف الحساب الإجمالي
        if (!empty($item['account_summary'])) {
            $acc = $item['account_summary'];
            $stmtAccSummary->execute([
                ':st_id' => $stId,
                ':st_name' => $st['full_name'] ?? '',
                ':class_name' => $acc['class_name'] ?? ($st['current_class_name'] ?? ''),
                ':academic_year' => $acc['academic_year'] ?? ($st['academic_year'] ?? ''),
                ':total_fees' => $acc['total_fees'] ?? 0.0,
                ':total_paid' => $acc['total_paid'] ?? 0.0,
                ':remaining' => $acc['remaining_balance'] ?? 0.0,
                ':status' => $acc['payment_status'] ?? 'مستمر',
                ':last_pay_date' => $acc['last_payment_date'] ?? ''
            ]);
        }

        // 3. الحركات المالية
        if (isset($item['financial_transactions']) && is_array($item['financial_transactions'])) {
            $stmtDelTrans->execute([$stId]);
            foreach ($item['financial_transactions'] as $tr) {
                $stmtTrans->execute([
                    ':trans_id' => $tr['trans_id'],
                    ':student_id' => $stId,
                    ':registration_code' => $tr['registration_code'] ?? ($st['student_code'] ?? ''),
                    ':trans_type' => $tr['trans_type'] ?? '',
                    ':acc_type_code' => $tr['acc_type_code'] ?? 1,
                    ':fee_type_id' => $tr['fee_type_id'] ?? null,
                    ':fee_type_name' => $tr['fee_type_name'] ?? '',
                    ':amount' => $tr['amount'] ?? 0.0,
                    ':receipt_no' => $tr['receipt_no'] ?? '',
                    ':trans_date' => $tr['trans_date'] ?? '',
                    ':due_date' => $tr['due_date'] ?? '',
                    ':academic_year' => $tr['academic_year'] ?? '',
                    ':month_no' => $tr['month_no'] ?? null,
                    ':notes' => $tr['notes'] ?? ''
                ]);
                $syncedTransCount++;
            }
        }

        // 4. الدرجات والنتائج
        if (isset($item['grades']) && is_array($item['grades'])) {
            $stmtDelGrades->execute([$stId]);
            foreach ($item['grades'] as $g) {
                $stmtGrade->execute([
                    ':student_id' => $stId,
                    ':registration_code' => $g['registration_code'] ?? ($st['student_code'] ?? ''),
                    ':student_name' => $g['student_name'] ?? ($st['full_name'] ?? ''),
                    ':class_name' => $g['class_name'] ?? ($st['current_class_name'] ?? ''),
                    ':subject_id' => $g['subject_id'] ?? null,
                    ':subject_name' => $g['subject_name'] ?? '',
                    ':term_no' => $g['term_no'] ?? 1,
                    ':term_name' => $g['term_name'] ?? 'الفصل الأول',
                    ':term_work_score' => $g['term_work_score'] ?? 0.0,
                    ':final_exam_score' => $g['final_exam_score'] ?? 0.0,
                    ':total_score' => $g['total_score'] ?? 0.0,
                    ':max_score' => $g['max_score'] ?? 100.0,
                    ':grade_estimate' => $g['grade_estimate'] ?? '',
                    ':academic_year' => $g['academic_year'] ?? ''
                ]);
                $syncedGradesCount++;
            }
        }

        // 5. سجل القيد والصفوف للأعوام
        if (isset($item['enrollment_history']) && is_array($item['enrollment_history'])) {
            $stmtDelEn = $pdo->prepare("DELETE FROM student_enrollments WHERE student_id = ?");
            $stmtDelEn->execute([$stId]);
            $stmtEn = $pdo->prepare("
                INSERT INTO student_enrollments (
                    student_id, registration_code, class_id, class_name,
                    branch_id, branch_name, year_id, academic_year, seat_number, is_stopped
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($item['enrollment_history'] as $en) {
                $stmtEn->execute([
                    $stId,
                    $en['registration_code'] ?? ($st['student_code'] ?? ''),
                    $en['class_id'] ?? null,
                    $en['class_name'] ?? '',
                    $en['branch_id'] ?? null,
                    $en['branch_name'] ?? '',
                    $en['year_id'] ?? null,
                    $en['academic_year'] ?? '',
                    $en['seat_number'] ?? null,
                    $en['is_stopped'] ?? 0
                ]);
            }
        }
    }

    // 5. تحديث جدول الأقساط إذا تم إرساله
    if (!empty($payload['fee_installments_plan']) && is_array($payload['fee_installments_plan'])) {
        $pdo->exec("DELETE FROM fee_installments_plan");
        $stmtInst = $pdo->prepare("
            INSERT INTO fee_installments_plan (class_id, class_name, fee_type_name, installment_order, percentage, start_date, due_date, academic_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($payload['fee_installments_plan'] as $inst) {
            $stmtInst->execute([
                $inst['class_id'] ?? null,
                $inst['class_name'] ?? '',
                $inst['fee_type_name'] ?? '',
                $inst['installment_order'] ?? 1,
                $inst['percentage'] ?? 0,
                $inst['start_date'] ?? '',
                $inst['due_date'] ?? '',
                $inst['academic_year'] ?? ''
            ]);
        }
    }

    // 6. تحديث الإشعارات
    if (!empty($payload['school_notifications']) && is_array($payload['school_notifications'])) {
        $pdo->exec("DELETE FROM school_notifications");
        $stmtNotif = $pdo->prepare("
            INSERT INTO school_notifications (notification_id, title, message_text, date_sent)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($payload['school_notifications'] as $notif) {
            $stmtNotif->execute([
                $notif['notification_id'] ?? 1,
                $notif['title'] ?? '',
                $notif['message_text'] ?? '',
                $notif['date_sent'] ?? date('Y-m-d')
            ]);
        }
    }

    $pdo->commit();

    // 7. حفظ بيانات التعريف (Metadata)
    $meta = [
        "last_sync_timestamp" => $syncTimestamp,
        "batch_index" => $batchIndex,
        "total_batches" => $totalBatches,
        "synced_students_in_batch" => $syncedStudentsCount,
        "synced_transactions_in_batch" => $syncedTransCount,
        "synced_grades_in_batch" => $syncedGradesCount
    ];
    file_put_contents(SYNC_META_PATH, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    jsonResponse(true, [
        "batch_index" => $batchIndex,
        "total_batches" => $totalBatches,
        "synced_students" => $syncedStudentsCount,
        "synced_transactions" => $syncedTransCount,
        "synced_grades" => $syncedGradesCount,
        "timestamp" => $syncTimestamp
    ], "تم استلام ومعالجة حزمة المزامنة بنجاح.");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, null, "حدث خطأ أثناء معالجة حزمة المزامنة: " . $e->getMessage(), 500);
}
