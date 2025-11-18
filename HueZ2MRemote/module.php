<?php

class HueZ2MRemote extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Basis-Properties
        $this->RegisterPropertyInteger('ActionVarId', 0);
        $this->RegisterPropertyInteger('DurationVarId', 0);
        $this->RegisterPropertyFloat('HoldThreshold', 2.0);
        $this->RegisterPropertyInteger('RoomInstanceId', 0);

        // Targets: Liste von Lampen/Gruppen
        $this->RegisterPropertyString('Targets', '[]');

        // Button-Mapping JSON
        $this->RegisterPropertyString('ButtonMap', '[]');

        // Optionen
        $this->RegisterPropertyBoolean('EnableDefaultProfile', true);

        // Timer für stufenloses Dimmen
        $this->RegisterTimer('DimLoop', 0, 'HZ2MR_DimLoop($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $actionVar = $this->ReadPropertyInteger('ActionVarId');

        // Z2M Action-Variable überwachen
        if ($actionVar > 0 && @IPS_VariableExists($actionVar)) {
            $this->RegisterMessage($actionVar, VM_UPDATE);
        }
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        switch ($message) {
            case VM_UPDATE:
                if ($senderID === $this->ReadPropertyInteger('ActionVarId')) {
                    $this->HandleActionVariable();
                }
                break;
        }
    }

    private function HandleActionVariable()
    {
        // Wird später gefüllt
        IPS_LogMessage('HueZ2MRemote', 'Action variable triggered.');
    }

    // Timer-Callback für stufenloses Dimmen
    public function DimLoop()
    {
        IPS_LogMessage('HueZ2MRemote', 'DimLoop tick.');
        // Später Logik einfügen
    }
}