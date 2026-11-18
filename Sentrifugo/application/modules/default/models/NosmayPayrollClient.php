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
        return !empty($opts['api']['url']) ? $opts['api']['url'] : 'http://host.docker.internal:8000/api/v1/sync/employee';
    }

    public static function getApiKey()
    {
        $opts = self::getOptions();
        return !empty($opts['api']['key']) ? $opts['api']['key'] : 'nosmay_sentrifugo_secure_sync_secret_key_2026';
    }

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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local testing

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
}