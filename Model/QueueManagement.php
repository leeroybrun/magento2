<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\MysqlMq\Model;

/**
 * Main class for managing MySQL implementation of message queue.
 */
class QueueManagement
{
    const MESSAGE_TOPIC = 'topic_name';
    const MESSAGE_BODY = 'body';
    const MESSAGE_ID = 'message_id';
    const MESSAGE_STATUS = 'status';
    const MESSAGE_UPDATED_AT = 'updated_at';
    const MESSAGE_QUEUE_ID = 'queue_id';
    const MESSAGE_QUEUE_NAME = 'queue_name';
    const MESSAGE_QUEUE_RELATION_ID = 'relation_id';
    const MESSAGE_NUMBER_OF_TRIALS = 'retries';

    const MESSAGE_STATUS_NEW = 2;
    const MESSAGE_STATUS_IN_PROGRESS = 3;
    const MESSAGE_STATUS_COMPLETE= 4;
    const MESSAGE_STATUS_RETRY_REQUIRED = 5;
    const MESSAGE_STATUS_ERROR = 6;
    const MESSAGE_STATUS_TO_BE_DELETED = 7;

    /**#@+
     * Cleanup configuration XML nodes
     */
    const XML_PATH_SUCCESSFUL_MESSAGES_LIFETIME = 'system/mysqlmq/successful_messages_lifetime';
    const XML_PATH_FAILED_MESSAGES_LIFETIME = 'system/mysqlmq/failed_messages_lifetime';
    const XML_PATH_RETRY_IN_PROGRESS_AFTER = 'system/mysqlmq/retry_inprogress_after';
    const XML_PATH_NEW_MESSAGES_LIFETIME = 'system/mysqlmq/new_messages_lifetime';
    /**#@-*/

    /**
     * @var \Magento\MysqlMq\Model\Resource\Queue
     */
    private $messageResource;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    private $timezone;

    /**
     * @var \Magento\MysqlMq\Model\Resource\MessageStatusCollectionFactory
     */
    private $messageStatusCollectionFactory;

    /**
     * @param Resource\Queue $messageResource
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\MysqlMq\Model\Resource\MessageStatusCollectionFactory $messageStatusCollectionFactory
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone
     */
    public function __construct(
        \Magento\MysqlMq\Model\Resource\Queue $messageResource,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\MysqlMq\Model\Resource\MessageStatusCollectionFactory $messageStatusCollectionFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone
    ) {
        $this->messageResource = $messageResource;
        $this->scopeConfig = $scopeConfig;
        $this->timezone = $timezone;
        $this->messageStatusCollectionFactory = $messageStatusCollectionFactory;
    }

    /**
     * Add message to all specified queues.
     *
     * @param string $topic
     * @param string $message
     * @param string[] $queueNames
     * @return $this
     */
    public function addMessageToQueues($topic, $message, $queueNames)
    {
        $messageId = $this->messageResource->saveMessage($topic, $message);
        $this->messageResource->linkQueues($messageId, $queueNames);
        return $this;
    }

    /**
     * Mark messages to be deleted if sufficient amount of time passed since last update
     * Delete marked messages
     *
     * @return void
     */
    public function markMessagesForDelete()
    {
        /**
         * Get timestamp related to current timezone
         */
        $now = $this->timezone->scopeTimeStamp();

        /**
         * Read configuration
         */
        $successfulLifetime = (int)$this->scopeConfig->getValue(
            self::XML_PATH_SUCCESSFUL_MESSAGES_LIFETIME,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $failureLifetime = (int)$this->scopeConfig->getValue(
            self::XML_PATH_SUCCESSFUL_MESSAGES_LIFETIME,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $newLifetime = (int)$this->scopeConfig->getValue(
            self::XML_PATH_NEW_MESSAGES_LIFETIME,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $retryInProgressAfter = (int)$this->scopeConfig->getValue(
            self::XML_PATH_RETRY_IN_PROGRESS_AFTER,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        /**
         * Do not make messages for deletion if configuration have 0 lifetime configured.
         */
        $statusesToDelete = [];
        if ($successfulLifetime > 0) {
            $statusesToDelete[] = self::MESSAGE_STATUS_COMPLETE;
        }

        if ($failureLifetime > 0) {
            $statusesToDelete[] = self::MESSAGE_STATUS_ERROR;
        }

        if ($newLifetime > 0) {
            $statusesToDelete[] = self::MESSAGE_STATUS_NEW;
        }

        if ($retryInProgressAfter > 0) {
            $statusesToDelete[] = self::MESSAGE_STATUS_IN_PROGRESS;
        }

        $collection = $this->messageStatusCollectionFactory->create()
            ->addFieldToFilter(
                'status',
                ['in' => $statusesToDelete]
            );

        /**
         * Update messages if lifetime is expired
         */
        foreach ($collection as $messageStatus) {
            if ($messageStatus->getStatus() == self::MESSAGE_STATUS_COMPLETE
                && strtotime($messageStatus->getUpdatedAt()) < ($now - $successfulLifetime)) {
                $messageStatus->setStatus(self::MESSAGE_STATUS_TO_BE_DELETED)
                    ->save();
            } else if ($messageStatus->getStatus() == self::MESSAGE_STATUS_ERROR
                && strtotime($messageStatus->getUpdatedAt()) < ($now - $failureLifetime)) {
                $messageStatus->setStatus(self::MESSAGE_STATUS_TO_BE_DELETED)
                    ->save();
            } else if ($messageStatus->getStatus() == self::MESSAGE_STATUS_IN_PROGRESS
                && strtotime($messageStatus->getUpdatedAt()) < ($now - $retryInProgressAfter)
            ) {
                if ($messageStatus->getRetries() < Consumer::MAX_NUMBER_OF_TRIALS) {
                    $this->pushToQueueForRetry($messageStatus->getId());
                } else {
                    $this->changeStatus($messageStatus->getId(), QueueManagement::MESSAGE_STATUS_ERROR);
                }
            } else if ($messageStatus->getStatus() == self::MESSAGE_STATUS_NEW
                && strtotime($messageStatus->getUpdatedAt()) < ($now - $newLifetime)
            ) {
                $messageStatus->setStatus(self::MESSAGE_STATUS_TO_BE_DELETED)
                    ->save();
            }
        }

        /**
         * Delete all messages which has To BE DELETED status in all the queues
         */
        $this->messageResource->deleteMarkedMessages();
    }

    /**
     * Read the specified number of messages from the specified queue.
     *
     * If queue does not contain enough messages, method is not waiting for more messages.
     *
     * @param string $queue
     * @param int|null $maxMessagesNumber
     * @return array <pre>
     * [
     *     [
     *          self::MESSAGE_ID => $messageId,
     *          self::MESSAGE_QUEUE_ID => $queuId,
     *          self::MESSAGE_TOPIC => $topic,
     *          self::MESSAGE_BODY => $body,
     *          self::MESSAGE_STATUS => $status,
     *          self::MESSAGE_UPDATED_AT => $updatedAt,
     *          self::MESSAGE_QUEUE_NAME => $queueName
     *          self::MESSAGE_QUEUE_RELATION_ID => $relationId
     *     ],
     *     ...
     * ]</pre>
     */
    public function readMessages($queue, $maxMessagesNumber = null)
    {
        $selectedMessages = $this->messageResource->getMessages($queue, $maxMessagesNumber);
        /* The logic below allows to prevent the same message being processed by several consumers in parallel */
        $selectedMessagesRelatedIds = [];
        foreach ($selectedMessages as $key => &$message) {
            /* Set message status here to avoid extra reading from DB after it is updated */
            $message[self::MESSAGE_STATUS] = self::MESSAGE_STATUS_IN_PROGRESS;
            $selectedMessagesRelatedIds[] = $message[self::MESSAGE_QUEUE_RELATION_ID];
        }
        $takenMessagesRelationIds = $this->messageResource->takeMessagesInProgress($selectedMessagesRelatedIds);
        if (count($selectedMessages) == count($takenMessagesRelationIds)) {
            return $selectedMessages;
        } else {
            $selectedMessages = array_combine($selectedMessagesRelatedIds, array_values($selectedMessages));
            return array_intersect_key($selectedMessages, array_flip($takenMessagesRelationIds));
        }
    }

    /**
     * Push message back to queue for one more processing trial. Affects message in particular queue only.
     *
     * @param int $messageRelationId
     * @return void
     */
    public function pushToQueueForRetry($messageRelationId)
    {
        $this->messageResource->pushBackForRetry($messageRelationId);
    }

    /**
     * Change status of messages.
     *
     * @param int[] $messageRelationIds
     * @param int $status
     * @return void
     */
    public function changeStatus($messageRelationIds, $status)
    {
        $this->messageResource->changeStatus($messageRelationIds, $status);
    }
}
