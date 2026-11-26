<?php

class Default_Model_NosmayPayrollClient
{
    public static function getOptions()
    {
        $front = Zend_Controller_Front::getInstance();
        if ($front && $front->getParam('bootstrap')) {
            $options = $front->getParam('bootstrap')->getOptions();
            return isset($options['payroll']) ? $options['payroll'] : array();
        }
        return array();
    }

    public static function getApiUrl()
    {
        $opts = self::getOptions();
        //return !empty($opts['api']['url']) ? $opts['api']['url'] : 'http://host.docker.internal:8000/api/v1/sync/employee';
        return !empty($opts['api']['url']) ? $opts['api']['url'] : 'https://payroll.nosmay.biz/api/v1/sync/employee';
    }

    public static function getApiKey()
    {
        $opts = self::getOptions();
        return !empty($opts['api']['key']) ? $opts['api']['key'] : 'nosmay_sentrifugo_secure_sync_secret_key_2026';
    }

    public static function syncEmployee(array $employeeData)
    {
        $empCode = !empty($employeeData['employeeId'])
            ? $employeeData['employeeId']
            : (!empty($employeeData['emp_code']) ? $employeeData['emp_code'] : '');

        if (empty($empCode)) {
            return array(
                'success' => false,
                'status'  => 'error',
                'message' => 'Employee ID is empty or not generated.'
            );
        }

        $payloadArray = array(
            'emp_code'           => $empCode,
            'firstname'          => !empty($employeeData['firstname']) ? $employeeData['firstname'] : '',
            'lastname'           => !empty($employeeData['lastname']) ? $employeeData['lastname'] : '',
            'title'              => !empty($employeeData['title']) ? $employeeData['title'] : null,
            'email'              => !empty($employeeData['emailaddress']) ? $employeeData['emailaddress'] : null,
            'contact'            => !empty($employeeData['contactnumber']) ? $employeeData['contactnumber'] : null,
            'sex'                => !empty($employeeData['sex']) ? $employeeData['sex'] : null,
            'emp_dob'            => !empty($employeeData['emp_dob']) ? $employeeData['emp_dob'] : null,
            'address'            => !empty($employeeData['address']) ? $employeeData['address'] : null,
            'date_joined'        => !empty($employeeData['date_of_joining'])
                                    ? $employeeData['date_of_joining']
                                    : (!empty($employeeData['date_joined']) ? $employeeData['date_joined'] : date('Y-m-d')),
            'department'         => !empty($employeeData['department']) ? $employeeData['department'] : null,
            'department_code'    => !empty($employeeData['department_code']) ? $employeeData['department_code'] : null,
            'job_title'          => !empty($employeeData['job_title']) ? $employeeData['job_title'] : null,
            'job_title_code'     => !empty($employeeData['job_title_code']) ? $employeeData['job_title_code'] : null,
            'position'           => !empty($employeeData['position']) ? $employeeData['position'] : null,
            'business_unit'      => !empty($employeeData['business_unit']) ? $employeeData['business_unit'] : null,
            'business_unit_code' => !empty($employeeData['business_unit_code']) ? $employeeData['business_unit_code'] : null,
            'status'             => !empty($employeeData['status']) ? $employeeData['status'] : 'active',
        );

        // Only send password when explicitly provided (initial creation)
        if (!empty($employeeData['password'])) {
            $payloadArray['password'] = $employeeData['password'];
        }

        $payload = json_encode($payloadArray);

        $ch = curl_init(self::getApiUrl());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Sentrifugo-Key: ' . self::getApiKey(),
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return array(
                'success' => false,
                'status'  => 'network_error',
                'message' => 'Cannot connect to Payroll server: ' . $curlError
            );
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return array(
                'success' => true,
                'status'  => 'success',
                'message' => isset($decoded['message']) ? $decoded['message'] : 'Synced successfully.',
                'data'    => $decoded
            );
        }

        $failureReason = 'Payroll server error (HTTP ' . $httpCode . ')';
        if (is_array($decoded) && !empty($decoded['message'])) {
            $failureReason = $decoded['message'];
        }

        return array(
            'success'   => false,
            'status'    => 'rejected',
            'http_code' => $httpCode,
            'message'   => $failureReason
        );
    }

    /**
     * Fetch the full authoritative employee master record from Sentrifugo DB and sync to Payroll.
     */
    public static function syncEmployeeByUserId($userId, $plainPassword = null)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array('success' => false, 'status' => 'error', 'message' => 'Invalid user ID.');
        }

        try {
            $db = Zend_Db_Table::getDefaultAdapter();
            $row = $db->fetchRow(
                "SELECT
                    u.id AS user_id,
                    u.employeeId,
                    u.firstname,
                    u.lastname,
                    u.userfullname,
                    u.emailaddress,
                    u.contactnumber,
                    u.isactive,
                    u.selecteddate,
                    e.date_of_joining,
                    e.office_number,
                    p.prefix AS prefix_name,
                    d.deptname AS department_name,
                    d.deptcode AS department_code,
                    bu.unitname AS business_unit_name,
                    bu.unitcode AS business_unit_code,
                    jt.jobtitlename AS job_title,
                    jt.jobtitlecode AS job_title_code,
                    pos.positionname AS position_name,
                    g.gendername AS gender_name,
                    pd.dob AS emp_dob,
                    cd.current_streetaddress,
                    cd.perm_streetaddress
                FROM main_users u
                LEFT JOIN main_employees e ON e.user_id = u.id
                LEFT JOIN main_prefix p ON p.id = e.prefix_id
                LEFT JOIN main_departments d ON d.id = e.department_id
                LEFT JOIN main_businessunits bu ON bu.id = e.businessunit_id
                LEFT JOIN main_jobtitles jt ON jt.id = COALESCE(e.jobtitle_id, u.jobtitle_id)
                LEFT JOIN main_positions pos ON pos.id = e.position_id
                LEFT JOIN main_emppersonaldetails pd ON pd.user_id = u.id
                LEFT JOIN main_gender g ON g.id = pd.genderid
                LEFT JOIN main_empcommunicationdetails cd ON cd.user_id = u.id
                WHERE u.id = ?
                LIMIT 1",
                array($userId)
            );

            if (empty($row) || empty($row['employeeId'])) {
                return array(
                    'success' => false,
                    'status'  => 'skipped',
                    'message' => 'Employee record or employeeId not found for user ' . $userId
                );
            }

            $firstname = trim((string) $row['firstname']);
            $lastname = trim((string) $row['lastname']);
            if ($firstname === '' && !empty($row['userfullname'])) {
                $parts = preg_split('/\s+/', trim((string) $row['userfullname']), 2);
                $firstname = $parts[0];
                if ($lastname === '' && isset($parts[1])) {
                    $lastname = $parts[1];
                }
            }

            $contact = !empty($row['contactnumber']) ? $row['contactnumber'] : $row['office_number'];
            $dateJoined = !empty($row['date_of_joining']) && $row['date_of_joining'] !== '0000-00-00'
                ? $row['date_of_joining']
                : (!empty($row['selecteddate']) && $row['selecteddate'] !== '0000-00-00' ? $row['selecteddate'] : date('Y-m-d'));
            $dob = !empty($row['emp_dob']) && $row['emp_dob'] !== '0000-00-00' ? $row['emp_dob'] : null;
            $address = !empty($row['current_streetaddress']) ? $row['current_streetaddress'] : $row['perm_streetaddress'];

            return self::syncEmployee(array(
                'employeeId'         => $row['employeeId'],
                'firstname'          => $firstname,
                'lastname'           => $lastname,
                'title'              => $row['prefix_name'],
                'emailaddress'       => $row['emailaddress'],
                'contactnumber'      => $contact,
                'sex'                => $row['gender_name'],
                'emp_dob'            => $dob,
                'address'            => $address,
                'date_of_joining'    => $dateJoined,
                'department'         => $row['department_name'],
                'department_code'    => $row['department_code'],
                'job_title'          => $row['job_title'],
                'job_title_code'     => $row['job_title_code'],
                'position'           => $row['position_name'],
                'business_unit'      => $row['business_unit_name'],
                'business_unit_code' => $row['business_unit_code'],
                'status'             => ((int) $row['isactive'] === 1) ? 'active' : 'inactive',
                'password'           => $plainPassword,
            ));
        } catch (Exception $e) {
            error_log('NosmayPayrollClient::syncEmployeeByUserId error: ' . $e->getMessage());
            return array(
                'success' => false,
                'status'  => 'error',
                'message' => $e->getMessage(),
            );
        }
    }

    public static function syncPassword(array $passwordData)
    {
        $empCode = !empty($passwordData['emp_code']) ? $passwordData['emp_code'] : '';

        if (empty($empCode)) {
            return array(
                'success' => false,
                'status'  => 'error',
                'message' => 'Employee code is empty.'
            );
        }

        $payload = json_encode(array(
            'emp_code' => $empCode,
            'email'    => !empty($passwordData['email']) ? $passwordData['email'] : null,
            'password' => !empty($passwordData['password']) ? $passwordData['password'] : null,
        ));

        // Dynamically uses the exact same base URL and API key as employee sync
        $passwordUrl = str_replace('sync/employee', 'sync/password', self::getApiUrl());

        $ch = curl_init($passwordUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Sentrifugo-Key: ' . self::getApiKey(),
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return array('success' => false, 'message' => 'cURL Error: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return array('success' => true, 'message' => 'Password synced successfully.');
        }

        return array('success' => false, 'message' => isset($decoded['message']) ? $decoded['message'] : 'HTTP Error ' . $httpCode);
    }

    public static function getLeaveTypeApiUrl()
    {
        return str_replace('sync/employee', 'sync/leave-type', self::getApiUrl());
    }

    public static function getLeaveRequestApiUrl()
    {
        return str_replace('sync/employee', 'sync/leave-request', self::getApiUrl());
    }

    public static function isPaidLeaveType($title, $code = '', $isPaidFlag = null)
    {
        if ($isPaidFlag !== null && $isPaidFlag !== '') {
            if ((int) $isPaidFlag === 0) {
                return false;
            }
        }

        $haystack = strtolower(trim($title . ' ' . $code));
        $unpaidKeywords = array('without pay', 'unpaid', 'non-paid', 'non paid', 'no pay', 'nopay', 'lwp');
        foreach ($unpaidKeywords as $kw) {
            if (strpos($haystack, $kw) !== false) {
                return false;
            }
        }

        return true;
    }

    private static function postToPayroll($url, array $payloadArray)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadArray));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Sentrifugo-Key: ' . self::getApiKey(),
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('Payroll sync cURL error (' . $url . '): ' . $curlError);
            return array('success' => false, 'message' => $curlError);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return array('success' => true, 'data' => $decoded);
        }

        error_log('Payroll sync HTTP ' . $httpCode . ' (' . $url . '): ' . $response);
        return array(
            'success' => false,
            'http_code' => $httpCode,
            'message' => isset($decoded['message']) ? $decoded['message'] : 'HTTP ' . $httpCode,
        );
    }

    public static function syncLeaveTypeById($leaveTypeId)
    {
        $leaveTypeId = (int) $leaveTypeId;
        if ($leaveTypeId <= 0) {
            return array('success' => false, 'message' => 'Invalid leave type ID.');
        }

        try {
            $db = Zend_Db_Table::getDefaultAdapter();
            $row = $db->fetchRow(
                "SELECT id, leavetype, numberofdays, leavecode, is_paid, isactive
                 FROM main_employeeleavetypes
                 WHERE id = ?
                 LIMIT 1",
                array($leaveTypeId)
            );

            if (empty($row)) {
                return array('success' => false, 'message' => 'Leave type not found.');
            }

            $isPaid = self::isPaidLeaveType(
                (string) $row['leavetype'],
                (string) $row['leavecode'],
                isset($row['is_paid']) ? $row['is_paid'] : null
            );

            return self::postToPayroll(self::getLeaveTypeApiUrl(), array(
                'sentrifugo_id'  => (int) $row['id'],
                'leave_title'    => (string) $row['leavetype'],
                'leave_code'     => !empty($row['leavecode']) ? (string) $row['leavecode'] : ('LT-' . $row['id']),
                'number_of_days' => (int) $row['numberofdays'],
                'is_paid'        => $isPaid,
                'leave_status'   => ((int) $row['isactive'] === 1) ? 'active' : 'inactive',
            ));
        } catch (Exception $e) {
            error_log('NosmayPayrollClient::syncLeaveTypeById error: ' . $e->getMessage());
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    public static function syncLeaveRequestById($leaveRequestId)
    {
        $leaveRequestId = (int) $leaveRequestId;
        if ($leaveRequestId <= 0) {
            return array('success' => false, 'message' => 'Invalid leave request ID.');
        }

        try {
            $db = Zend_Db_Table::getDefaultAdapter();
            $row = $db->fetchRow(
                "SELECT
                    lr.id,
                    lr.user_id,
                    lr.leavetypeid,
                    lr.leaveday,
                    lr.from_date,
                    lr.to_date,
                    lr.leavestatus,
                    lr.appliedleavescount,
                    lr.reason,
                    lr.isactive,
                    u.employeeId,
                    lt.leavetype AS leave_title,
                    lt.leavecode AS leave_code,
                    lt.is_paid
                 FROM main_leaverequest lr
                 INNER JOIN main_users u ON u.id = lr.user_id
                 LEFT JOIN main_employeeleavetypes lt ON lt.id = lr.leavetypeid
                 WHERE lr.id = ?
                 LIMIT 1",
                array($leaveRequestId)
            );

            if (empty($row) || empty($row['employeeId'])) {
                return array('success' => false, 'message' => 'Leave request or employeeId not found.');
            }

            $statusMap = array(
                'Pending for approval' => 'pending',
                'Approved'             => 'approved',
                'Rejected'             => 'rejected',
                'Cancel'               => 'cancelled',
            );
            $rawStatus = (string) $row['leavestatus'];
            $normalizedStatus = isset($statusMap[$rawStatus]) ? $statusMap[$rawStatus] : 'pending';
            if ((int) $row['isactive'] === 0) {
                $normalizedStatus = 'cancelled';
            }

            $title = !empty($row['leave_title']) ? (string) $row['leave_title'] : 'Leave';
            $code = !empty($row['leave_code']) ? (string) $row['leave_code'] : ('LT-' . $row['leavetypeid']);
            $isPaid = self::isPaidLeaveType($title, $code, isset($row['is_paid']) ? $row['is_paid'] : null);

            return self::postToPayroll(self::getLeaveRequestApiUrl(), array(
                'sentrifugo_leave_request_id' => (int) $row['id'],
                'emp_code'                    => (string) $row['employeeId'],
                'leave_type_sentrifugo_id'    => (int) $row['leavetypeid'],
                'leave_title'                 => $title,
                'leave_code'                  => $code,
                'is_paid'                     => $isPaid,
                'from_date'                   => (string) $row['from_date'],
                'to_date'                     => !empty($row['to_date']) ? (string) $row['to_date'] : (string) $row['from_date'],
                'days_count'                  => (float) $row['appliedleavescount'],
                'leave_day_type'              => ((int) $row['leaveday'] === 2) ? 'half' : 'full',
                'sentrifugo_status'           => $normalizedStatus,
                'reason'                      => $row['reason'],
            ));
        } catch (Exception $e) {
            error_log('NosmayPayrollClient::syncLeaveRequestById error: ' . $e->getMessage());
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    public static function getEmployeePayslips($empCode)
    {
        $empCode = trim((string) $empCode);
        if ($empCode === '') {
            return array(
                'success'  => false,
                'message'  => 'Employee code is missing.',
                'payslips' => array(),
            );
        }

        $url = str_replace(
            'sync/employee',
            'employees/' . rawurlencode($empCode) . '/payslips',
            self::getApiUrl()
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-Sentrifugo-Key: ' . self::getApiKey(),
            'Accept: application/json',
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return array(
                'success'  => false,
                'message'  => 'Cannot connect to Payroll server: ' . $curlError,
                'payslips' => array(),
            );
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded)) {
            return array(
                'success'             => true,
                'payslips'            => isset($decoded['payslips']) ? $decoded['payslips'] : array(),
                'portal_payslips_url' => isset($decoded['portal_payslips_url']) ? $decoded['portal_payslips_url'] : null,
                'message'             => '',
            );
        }

        return array(
            'success'  => false,
            'message'  => isset($decoded['message']) ? $decoded['message'] : ('Payroll error (HTTP ' . $httpCode . ')'),
            'payslips' => array(),
        );
    }
}