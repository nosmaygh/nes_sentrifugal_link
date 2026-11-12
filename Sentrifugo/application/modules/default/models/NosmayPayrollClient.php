<?php

class Default_Model_NosmayPayrollClient
{
    //const PAYROLL_API_URL = 'http://host.docker.internal:8000/api/v1/sync/employee';
    const PAYROLL_API_URL = 'https://payroll.nosmay.biz/api/v1/sync/employee';
    const API_KEY = 'nosmay_sentrifugo_secure_sync_secret_key_2026';

    /**
     * Send employee details to Nosmay Payroll
     *
     * @param array $employeeData
     * @return array [
     *    'success' => bool,
     *    'status'  => string,
     *    'message' => string,
     *    'data'    => array|null
     * ]
     */
    public static function syncEmployee(array $employeeData)
    {
        $empCode = !empty($employeeData['employeeId']) ? $employeeData['employeeId'] : '';

        if (empty($empCode)) {
            return array(
                'success' => false,
                'status'  => 'error',
                'message' => 'Employee ID is empty or not generated.'
            );
        }

        $payload = json_encode(array(
            'emp_code'    => $empCode,
            'firstname'   => !empty($employeeData['firstname']) ? $employeeData['firstname'] : '',
            'lastname'    => !empty($employeeData['lastname']) ? $employeeData['lastname'] : '',
            'email'       => !empty($employeeData['emailaddress']) ? $employeeData['emailaddress'] : null,
            'contact'     => !empty($employeeData['contactnumber']) ? $employeeData['contactnumber'] : null,
            'date_joined' => !empty($employeeData['date_of_joining']) ? $employeeData['date_of_joining'] : date('Y-m-d'),
            'password'    => !empty($employeeData['password']) ? $employeeData['password'] : null,
        ));

        $ch = curl_init(self::PAYROLL_API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Sentrifugo-Key: ' . self::API_KEY,
            'Accept: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 1. Check for Network / Connectivity Errors
        if ($curlError) {
            error_log('Nosmay Payroll Network Error: ' . $curlError);
            return array(
                'success' => false,
                'status'  => 'network_error',
                'message' => 'Cannot connect to Payroll server: ' . $curlError
            );
        }

        $decoded = json_decode($response, true);

        // 2. Check for Successful HTTP Status (200 OK or 201 Created)
        if ($httpCode >= 200 && $httpCode < 300) {
            return array(
                'success' => true,
                'status'  => 'success',
                'message' => isset($decoded['message']) ? $decoded['message'] : 'Synced successfully.',
                'data'    => $decoded
            );
        }

        // 3. Extract Detailed Failure Message from Payroll Response
        $failureReason = 'Payroll server error (HTTP ' . $httpCode . ')';

        if (is_array($decoded)) {
            if (!empty($decoded['message'])) {
                $failureReason = $decoded['message'];
            }
            // Flatten Laravel validation errors if present (e.g. "The emp_code has already been taken")
            if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                $validationMessages = array();
                foreach ($decoded['errors'] as $field => $errors) {
                    $validationMessages[] = is_array($errors) ? implode(', ', $errors) : $errors;
                }
                $failureReason .= ' [' . implode('; ', $validationMessages) . ']';
            }
        } elseif (!empty($response)) {
            // Raw HTML or string error from server
            $failureReason = substr(strip_tags($response), 0, 200);
        }

        error_log('Nosmay Payroll Sync Rejected: ' . $failureReason);

        return array(
            'success'   => false,
            'status'    => 'rejected',
            'http_code' => $httpCode,
            'message'   => $failureReason
        );
    }
}