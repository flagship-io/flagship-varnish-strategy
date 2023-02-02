<?php

class Flagship
{
    private $envId;
    private $apiKey;

    protected $decision = null;

    public function __construct($envId, $apiKey)
    {
        $this->envId = $envId;
        $this->apiKey = $apiKey;
    }

    public function start($visitorID, $context)
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://decision.flagship.io/v2/' . $this->getEnvId() . '/flags',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>
            '{
                    "visitor_id": "' .
                $visitorID .
                '",
                    "context": ' .
                $context .
                ',
                    "trigger_hit": false
                }',
            CURLOPT_HTTPHEADER => [
                'Connection: keep-alive',
                'x-api-key: ' . $this->getApiKey(),
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);
        $this->decision = json_decode($response);
        return $this->decision;
    }

    public function getDecision()
    {
        return $this->decision;
    }

    public function getEnvId()
    {
        return $this->envId;
    }

    public function getApiKey()
    {
        return $this->apiKey;
    }

    public function getHashKey()
    {
        if ($this->decision == null) {
            return false;
        }
        $experiences = [];
        foreach ($this->decision as $flag) {
            $experience = "{$flag->metadata->campaignId}:{$flag->metadata->variationId}";
            if (in_array($experience, $experiences)) {
                continue;
            }
            $experiences[] = $experience;
        }

        $experiences = implode("|", $experiences);
        return hash("sha256", $experiences);
    }

    public function getFlag($key, $default)
    {
        if ($this->decision === null || !isset($this->decision->{$key})) {
            return $default;
        }
        return $this->decision->{$key}->value;
    }

    public function generateUID()
    {
        return 'varnish-v' . rand();
    }
}
