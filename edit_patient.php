<?php
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_helper.php';

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$allowedRoles = ['admin', 'receptionist', 'clinician'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. Your account cannot edit patient records.');
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['edit_patient_csrf_token'])) {
    $_SESSION['edit_patient_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Patient ID
|--------------------------------------------------------------------------
*/

$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = filter_var(
        $_POST['patient_id'] ?? null,
        FILTER_VALIDATE_INT
    );
}

if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('Invalid patient ID. Open a patient record from the patient list.');
}

/*
|--------------------------------------------------------------------------
| Load existing patient
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare(
        'SELECT id, patient_number, first_name, last_name,
                gender, date_of_birth, phone, address,
                next_of_kin, next_of_kin_phone
         FROM patients
         WHERE id = :id
         LIMIT 1'
    );

    $stmt->execute(['id' => $patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Patient edit lookup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the patient record.');
}

if (!$patient) {
    http_response_code(404);
    exit('Patient record not found.');
}

/*
|--------------------------------------------------------------------------
| Save edits
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (
        !isset($_SESSION['edit_patient_csrf_token']) ||
        !hash_equals($_SESSION['edit_patient_csrf_token'], $token)
    ) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $gender = (string) ($_POST['gender'] ?? '');
        $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $nextOfKin = trim((string) ($_POST['next_of_kin'] ?? ''));
        $nextOfKinPhone = trim((string) ($_POST['next_of_kin_phone'] ?? ''));

        if (
            $firstName === '' ||
            $lastName === '' ||
            !in_array($gender, ['Male', 'Female', 'Other'], true)
        ) {
            $error = 'Enter the patient name and select a valid gender.';
        } elseif (
            $dob !== '' &&
            (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ||
                !checkdate(
                    (int) substr($dob, 5, 2),
                    (int) substr($dob, 8, 2),
                    (int) substr($dob, 0, 4)
                ) ||
                $dob > date('Y-m-d')
            )
        ) {
            $error = 'Enter a valid date of birth that is not in the future.';
        } elseif (
            strlen($firstName) > 100 ||
            strlen($lastName) > 100 ||
            strlen($phone) > 30 ||
            strlen($address) > 255 ||
            strlen($nextOfKin) > 150 ||
            strlen($nextOfKinPhone) > 30
        ) {
            $error = 'One or more fields exceed the permitted length.';
        } else {
            try {
                $update = $pdo->prepare(
                    'UPDATE patients
                     SET first_name = :first,
                         last_name = :last,
                         gender = :gender,
                         date_of_birth = :dob,
                         phone = :phone,
                         address = :address,
                         next_of_kin = :kin,
                         next_of_kin_phone = :kin_phone
                     WHERE id = :id'
                );

                $update->execute([
                    'first' => $firstName,
                    'last' => $lastName,
                    'gender' => $gender,
                    'dob' => $dob !== '' ? $dob : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'address' => $address !== '' ? $address : null,
                    'kin' => $nextOfKin !== '' ? $nextOfKin : null,
                    'kin_phone' => $nextOfKinPhone !== ''
                        ? $nextOfKinPhone : null,
                    'id' => $patientId
                ]);

                /*
                 * Record the edit only after the update succeeds.
                 * Do not place patient-identifying information in details.
                 */
                recordAudit(
                    $pdo,
                    'patient',
                    'Patient record updated',
                    'success',
                    'An existing patient record was updated.'
                );

                $_SESSION['edit_patient_csrf_token'] =
                    bin2hex(random_bytes(32));

                header(
                    'Location: edit_patient.php?id=' .
                    (int) $patientId . '&updated=1'
                );
                exit;

            } catch (PDOException $e) {
                error_log('Patient update error: ' . $e->getMessage());
                $error = 'The patient record could not be updated. Please try again.';
            }
        }
    }

    /*
     * Retain submitted values after validation errors so staff
     * do not have to enter them all again.
     */
    $patient['first_name'] = $firstName;
    $patient['last_name'] = $lastName;
    $patient['gender'] = $gender;
    $patient['date_of_birth'] = $dob;
    $patient['phone'] = $phone;
    $patient['address'] = $address;
    $patient['next_of_kin'] = $nextOfKin;
    $patient['next_of_kin_phone'] = $nextOfKinPhone;
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $success = 'Patient record updated successfully.';
}

function editPatientEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Patient | AZU Clinic PIMS</title>

    <style>
        :root {
            --navy: #202b83;
            --navy-dark: #17205f;
            --orange: #ed791c;
            --orange-light: #fff1e5;
            --background: #f4f6fb;
            --white: #fff;
            --text: #263247;
            --muted: #738096;
            --border: #e3e8f1;
            --green: #167647;
            --red: #b42318;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .topbar {
            padding: 18px 5%;
            background: var(--white);
            border-bottom: 4px solid var(--orange);
        }

        .brand {
            color: var(--navy);
            font-size: 21px;
            font-weight: 800;
        }

        .brand span {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
            font-weight: normal;
        }

        .container {
            max-width: 900px;
            margin: 32px auto;
            padding: 0 20px 40px;
        }

        .heading { margin-bottom: 22px; }

        h1 {
            margin: 0 0 8px;
            color: var(--navy);
            font-size: 28px;
        }

        .muted {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .panel {
            padding: 25px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 4px 18px #202b830a;
        }

        .patient-number {
            margin-bottom: 22px;
            padding: 13px;
            border-radius: 8px;
            background: #edf0ff;
            color: var(--navy);
            font-weight: bold;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: var(--navy);
            font-size: 13px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7deeb;
            border-radius: 7px;
            background: white;
            color: var(--text);
            font-size: 14px;
        }

        input:focus, select:focus {
            outline: 2px solid #dce1ff;
            border-color: var(--navy);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 24px;
        }

        .button {
            display: inline-block;
            padding: 12px 18px;
            border: 0;
            border-radius: 7px;
            background: var(--navy);
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
        }

        .button.orange { background: var(--orange); }

        .message {
            margin-bottom: 18px;
            padding: 13px 15px;
            border-radius: 7px;
            line-height: 1.5;
        }

        .success {
            background: #e7f7ef;
            border-left: 4px solid var(--green);
            color: #17633c;
        }

        .error {
            background: #fff0ef;
            border-left: 4px solid var(--red);
            color: #a52820;
        }

        @media (max-width: 620px) {
            .grid { grid-template-columns: 1fr; }
            .panel { padding: 18px; }
            h1 { font-size: 24px; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="brand">
        AZU Clinic PIMS
        <span>Patient Information Management System</span>
    </div>
</header>

<main class="container">
    <div class="heading">
        <h1>Edit Patient Record</h1>
        <div class="muted">
            Update patient information carefully. Changes are recorded
            in the system audit log.
        </div>
    </div>

    <section class="panel">
        <div class="patient-number">
            Patient Number:
            <?= editPatientEscape($patient['patient_number']) ?>
        </div>

        <?php if ($success !== ''): ?>
            <div class="message success">
                <?= editPatientEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message error">
                <?= editPatientEscape($error) ?>
            </div>
        <?php endif; ?>

        <form method="post"
              action="edit_patient.php?id=<?= (int) $patientId ?>">

            <input type="hidden" name="patient_id"
                   value="<?= (int) $patientId ?>">

            <input type="hidden" name="csrf_token"
                   value="<?= editPatientEscape(
                       $_SESSION['edit_patient_csrf_token']
                   ) ?>">

            <div class="grid">
                <div>
                    <label for="first_name">First name *</label>
                    <input id="first_name" name="first_name"
                           maxlength="100" required
                           value="<?= editPatientEscape($patient['first_name']) ?>">
                </div>

                <div>
                    <label for="last_name">Last name *</label>
                    <input id="last_name" name="last_name"
                           maxlength="100" required
                           value="<?= editPatientEscape($patient['last_name']) ?>">
                </div>

                <div>
                    <label for="gender">Gender *</label>
                    <select id="gender" name="gender" required>
                        <option value="">Select gender</option>
                        <?php foreach (['Female', 'Male', 'Other'] as $option): ?>
                            <option value="<?= $option ?>"
                                <?= $patient['gender'] === $option ? 'selected' : '' ?>>
                                <?= $option ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="date_of_birth">Date of birth</label>
                    <input type="date" id="date_of_birth"
                           name="date_of_birth"
                           max="<?= date('Y-m-d') ?>"
                           value="<?= editPatientEscape($patient['date_of_birth']) ?>">
                </div>

                <div>
                    <label for="phone">Phone number</label>
                    <input id="phone" name="phone" maxlength="30"
                           value="<?= editPatientEscape($patient['phone']) ?>">
                </div>

                <div>
                    <label for="address">Address</label>
                    <input id="address" name="address" maxlength="255"
                           value="<?= editPatientEscape($patient['address']) ?>">
                </div>

                <div>
                    <label for="next_of_kin">Next of kin</label>
                    <input id="next_of_kin" name="next_of_kin" maxlength="150"
                           value="<?= editPatientEscape($patient['next_of_kin']) ?>">
                </div>

                <div>
                    <label for="next_of_kin_phone">Next-of-kin phone</label>
                    <input id="next_of_kin_phone"
                           name="next_of_kin_phone" maxlength="30"
                           value="<?= editPatientEscape($patient['next_of_kin_phone']) ?>">
                </div>
            </div>

            <div class="actions">
                <button class="button orange" type="submit">
                    Save Changes
                </button>

                <a class="button" href="patients.php">
                    Back to Patients
                </a>
            </div>
        </form>
    </section>
</main>

</body>
</html>
