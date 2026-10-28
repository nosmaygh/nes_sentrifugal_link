<?php

class Default_Model_NosmayPayrollClient
{
    const PAYROLL_API_URL = 'http://host.docker.internal:8000/api/v1/sync/employee';
    const API_KEY = 'nosmay_sentrifugo_secure_sync_secret_key_2026';

    /**
     * Send employee details to Nosmay Payroll
     *
     * @param array $employeeData
     * @return array
     */
    public static function syncEmployee(array $employeeData)
    {
        $empCode = !empty($employeeData['employeeId']) ? $employeeData['employeeId'] : '';

        if (empty($empCode)) {
            return array('status' => 'error', 'message' => 'Empty employee code');
        }

        $payload = json_encode(array(
            'emp_code'    => $empCode,
            'firstname'   => !empty($employeeData['firstname']) ? $employeeData['firstname'] : '',
            'lastname'    => !empty($employeeData['lastname']) ? $employeeData['lastname'] : '',
            'email'       => !empty($employeeData['emailaddress']) ? $employeeData['emailaddress'] : null,
            'contact'     => !empty($employeeData['contactnumber']) ? $employeeData['contactnumber'] : null,
            'date_joined' => !empty($employeeData['date_of_joining']) ? $employeeData['date_of_joining'] : date('Y-m-d'),
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('Nosmay Payroll Sync Error: ' . $curlError);
            return array('status' => 'error', 'message' => $curlError);
        }

        return json_decode($response, true);
    }
}