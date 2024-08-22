<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $this->validateInputData($data);

        $resellerId = (int)$data['resellerId'];
        $notificationType = (int)$data['notificationType'];

        $reseller = Seller::getById($resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }

        $client = $this->getClient($data, $resellerId);
        $creator = $this->getEmployee((int)$data['creatorId'], 'Creator');
        $expert = $this->getEmployee((int)$data['expertId'], 'Expert');

        $differences = $this->getDifferences($notificationType, $data, $resellerId);

        $templateData = $this->prepareTemplateData($data, $creator, $expert, $client, $differences);

        $this->validateTemplateData($templateData);

        $emailFrom = getResellerEmailFrom($resellerId);
        $result = $this->sendNotifications($emailFrom, $resellerId, $client, $notificationType, $templateData);

        return $result;
    }

    private function validateInputData(array $data): void
    {
        if (empty((int)$data['resellerId'])) {
            throw new \InvalidArgumentException('Empty resellerId', 400);
        }
        if (empty((int)$data['notificationType'])) {
            throw new \InvalidArgumentException('Empty notificationType', 400);
        }
    }

    private function getClient(array $data, int $resellerId)
    {
        $client = Contractor::getById((int)$data['clientId']);
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('Client not found!', 400);
        }
        return $client;
    }

    private function getEmployee(int $id, string $role): Employee
    {
        $employee = Employee::getById($id);
        if ($employee === null) {
            throw new \Exception("{$role} not found!", 400);
        }
        return $employee;
    }

    private function getDifferences(int $notificationType, array $data, int $resellerId): string
    {
        if ($notificationType === self::TYPE_NEW) {
            return __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO' => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }
        return '';
    }

    private function prepareTemplateData(array $data, Employee $creator, Employee $expert, Contractor $client, string $differences): array
    {
        return [
            'COMPLAINT_ID' => (int)$data['complaintId'],
            'COMPLAINT_NUMBER' => (string)$data['complaintNumber'],
            'CREATOR_ID' => (int)$data['creatorId'],
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_ID' => (int)$data['expertId'],
            'EXPERT_NAME' => $expert->getFullName(),
            'CLIENT_ID' => (int)$data['clientId'],
            'CLIENT_NAME' => $client->getFullName() ?: $client->name,
            'CONSUMPTION_ID' => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string)$data['agreementNumber'],
            'DATE' => (string)$data['date'],
            'DIFFERENCES' => $differences,
        ];
    }

    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    private function sendNotifications(string $emailFrom, int $resellerId, Contractor $client, int $notificationType, array $templateData): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $client->email,
                        'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }
            if (!empty($client->mobile)) {
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}