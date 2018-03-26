<?php

class NetatmoWeatherIO extends IPSModule
{
    private $scriptName = 'NetatmoWeatherIO';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Netatmo_User', '');
        $this->RegisterPropertyString('Netatmo_Password', '');
        $this->RegisterPropertyString('Netatmo_Client', '');
        $this->RegisterPropertyString('Netatmo_Secret', '');

        $this->RegisterPropertyInteger('UpdateDataInterval', '5');

        $this->RegisterTimer('UpdateDataWeather', 0, 'NetatmoWeatherIO_UpdateData(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $netatmo_user = $this->ReadPropertyString('Netatmo_User');
        $netatmo_password = $this->ReadPropertyString('Netatmo_Password');
        $netatmo_client = $this->ReadPropertyString('Netatmo_Client');
        $netatmo_secret = $this->ReadPropertyString('Netatmo_Secret');

        if ($netatmo_user != '' && $netatmo_password != '' && $netatmo_client != '' && $netatmo_secret != '') {
            if (IPS_GetKernelRunlevel() == 10103) { // IPS läuft dann gleich Daten abholen
                $this->UpdateData();
            }
            $this->SetUpdateInterval();
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }
    }

    protected function SetValue($Ident, $Value)
    {
        if (IPS_GetKernelVersion() >= 5) {
            parent::SetValue($Ident, $Value);
        } else {
            SetValue($this->GetIDForIdent($Ident), $Value);
        }
    }

    protected function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('UpdateDataInterval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->SetTimerInterval('UpdateDataWeather', $msec);
    }

    protected function SendData($data)
    {
        $this->SendDebug($this->scriptName, 'SendData(): data=' . print_r($data, true), 0);
        $this->SendDataToChildren(json_encode(['DataID' => '{2D42552F-2545-9145-D3C8-A299E3FDC6EA}', 'Buffer' => $data]));
    }

    public function ForwardData($JSONString)
    {
        $data = $this->GetBuffer('LastData');
        $this->SendDebug($this->scriptName, 'ForwardData(): data=' . print_r($data, true), 0);
        return $data;
    }

    public function UpdateData()
    {
        $netatmo_auth_url = 'https://api.netatmo.net/oauth2/token';
        $netatmo_netatmo_data_url = 'https://api.netatmo.net/api/devicelist';

        $netatmo_user = $this->ReadPropertyString('Netatmo_User');
        $netatmo_password = $this->ReadPropertyString('Netatmo_Password');
        $netatmo_client = $this->ReadPropertyString('Netatmo_Client');
        $netatmo_secret = $this->ReadPropertyString('Netatmo_Secret');

        $dtoken = $this->GetBuffer('Token');
        $jtoken = json_decode($dtoken, true);
        $token = isset($jtoken['token']) ? $jtoken['token'] : '';
        $token_expiration = isset($jtoken['token_expiration']) ? $jtoken['token_expiration'] : 0;

        if ($token_expiration < time()) {
            $postdata = [
                'grant_type'    => 'password',
                'client_id'     => $netatmo_client,
                'client_secret' => $netatmo_secret,
                'username'      => $netatmo_user,
                'password'      => $netatmo_password,
                'scope'         => 'read_station'
            ];

            $this->SendDebug($this->scriptName, "netatmo-auth-url=$netatmo_auth_url, postdata=" . print_r($postdata, true), 0);

            $token = '';
            $token_expiration = 0;

            $do_abort = false;
            $response = $this->do_HttpRequest($netatmo_auth_url, $postdata);
            if ($response != '') {
                $params = json_decode($response, true);
                if ($params['access_token'] == '') {
                    $statuscode = 204;
                    $err = "no 'access_token' in response from netatmo";
                    echo "statuscode=$statuscode, err=$err";
                    $this->SendDebug($this->scriptName, $err, 0);
                    $this->SetStatus($statuscode);
                    $do_abort = true;
                } else {
                    $token = $params['access_token'];
                    $expires_in = $params['expires_in'];
                    $token_expiration = time() + $expires_in - 60;
                }
            } else {
                $do_abort = true;
            }

            $this->SendDebug($this->scriptName, 'token=' . $token . ', expiration=' . $token_expiration, 0);

            $jtoken = [
                    'token'            => $token,
                    'token_expiration' => $token_expiration
                ];
            $this->SetBuffer('Token', json_encode($jtoken));

            if ($do_abort) {
                $this->SetBuffer('LastData', '');
                $this->SendData('');
                return -1;
            }
        }

        // Anfrage mit Token
        $netatmo_data_url = $netatmo_netatmo_data_url . '?access_token=' . $token;

        $this->SendDebug($this->scriptName, "netatmo-data-url=$netatmo_data_url", 0);

        $do_abort = false;
        $data = $this->do_HttpRequest($netatmo_data_url);
        if ($data != '') {
            $err = '';
            $statuscode = 0;
            $netatmo = json_decode($data, true);
            $status = $netatmo['status'];
            if ($status != 'ok') {
                $err = "got status \"$status\" from netamo";
                $statuscode = 204;
            } else {
                $devices = $netatmo['body']['devices'];
                if (!count($devices)) {
                    $err = 'data contains no station';
                    $statuscode = 205;
                }
            }
            if ($statuscode) {
                echo "statuscode=$statuscode, err=$err";
                $this->SendDebug($this->scriptName, $err, 0);
                $this->SetStatus($statuscode);
                $do_abort = true;
            }
        } else {
            $do_abort = true;
        }

        if ($do_abort) {
            $this->SendData('');
            $this->SetBuffer('LastData', '');
            return -1;
        }

        $this->SetStatus(102);

        $this->SendData($data);
        $this->SetBuffer('LastData', $data);
    }

    private function do_HttpRequest($url, $postdata = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($postdata != '') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $cdata = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $statuscode = 0;
        $err = '';
        $data = '';
        if ($httpcode != 200) {
            if ($httpcode == 400 || $httpcode == 401) {
                $statuscode = 201;
                $err = "got http-code $httpcode (unauthorized) from netatmo";
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = 202;
                $err = "got http-code $httpcode (server error) from netatmo";
            } else {
                $statuscode = 203;
                $err = "got http-code $httpcode from netatmo";
            }
        } elseif ($cdata == '') {
            $statuscode = 204;
            $err = 'no data from netatmo';
        } else {
            $jdata = json_decode($cdata, true);
            if ($jdata == '') {
                $statuscode = 204;
                $err = 'malformed response from netatmo';
            } else {
                $data = $cdata;
            }
        }

        if ($statuscode) {
            echo "statuscode=$statuscode, err=$err";
            $this->SendDebug($this->scriptName, $err, 0);
            $this->SetStatus($statuscode);
        }

        return $data;
    }
}