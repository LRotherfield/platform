<?php

namespace Oro\Bundle\ImportExportBundle\Job;

use Symfony\Bridge\Doctrine\ManagerRegistry;

use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;

use Akeneo\Bundle\BatchBundle\Connector\ConnectorRegistry;
use Akeneo\Bundle\BatchBundle\Entity\JobInstance;
use Akeneo\Bundle\BatchBundle\Entity\JobExecution;
use Akeneo\Bundle\BatchBundle\Job\BatchStatus;
use Akeneo\Bundle\BatchBundle\Job\DoctrineJobRepository as BatchJobRepository;

use Oro\Bundle\ImportExportBundle\Context\ContextRegistry;
use Oro\Bundle\ImportExportBundle\Exception\RuntimeException;
use Oro\Bundle\ImportExportBundle\Exception\LogicException;

class JobExecutor
{
    const CONNECTOR_NAME = 'oro_importexport';

    const JOB_EXPORT_TO_CSV = 'entity_export_to_csv';
    const JOB_EXPORT_TEMPLATE_TO_CSV = 'entity_export_template_to_csv';
    const JOB_IMPORT_FROM_CSV = 'entity_import_from_csv';
    const JOB_VALIDATE_IMPORT_FROM_CSV = 'entity_import_validation_from_csv';

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var ConnectorRegistry
     */
    protected $batchJobRegistry;

    /**
     * @var ContextRegistry
     */
    protected $contextRegistry;

    /**
     * @var ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @var BatchJobRepository
     */
    protected $batchJobRepository;

    /**
     * @param ConnectorRegistry $jobRegistry
     * @param BatchJobRepository $batchJobRepository
     * @param ContextRegistry $contextRegistry
     * @param ManagerRegistry $managerRegistry
     */
    public function __construct(
        ConnectorRegistry $jobRegistry,
        BatchJobRepository $batchJobRepository,
        ContextRegistry $contextRegistry,
        ManagerRegistry $managerRegistry
    ) {
        $this->batchJobRegistry   = $jobRegistry;
        $this->batchJobRepository = $batchJobRepository;
        $this->contextRegistry    = $contextRegistry;
        $this->managerRegistry    = $managerRegistry;
    }

    /**
     * @param string $jobType
     * @param string $jobName
     * @param array $configuration
     * @return JobResult
     */
    public function executeJob($jobType, $jobName, array $configuration = [])
    {
        $this->initialize();

        // create and persist job instance and job execution
        $jobInstance = new JobInstance(self::CONNECTOR_NAME, $jobType, $jobName);
        $jobInstance->setCode($this->generateJobCode($jobName));
        $jobInstance->setLabel(sprintf('%s.%s', $jobType, $jobName));
        $jobInstance->setRawConfiguration($configuration);

        // persist batch entities
        $this->batchJobRepository->getJobManager()->persist($jobInstance);
        $jobExecution = $this->batchJobRepository->createJobExecution($jobInstance);

        // do job
        $jobResult = $this->doJob($jobInstance, $jobExecution);

        // set data to JobResult
        $jobResult->setJobId($jobInstance->getId());
        $jobResult->setJobCode($jobInstance->getCode());

        // TODO: Find a way to work with multiple amount of job and step executions
        // TODO: https://magecore.atlassian.net/browse/BAP-2600
        /** @var JobExecution $jobExecution */
        $jobExecution = $jobInstance->getJobExecutions()->first();
        if ($jobExecution) {
            $stepExecution = $jobExecution->getStepExecutions()->first();
            if ($stepExecution) {
                $context = $this->contextRegistry->getByStepExecution($stepExecution);
                $jobResult->setContext($context);
            }
        }

        return $jobResult;
    }

    /**
     * @param JobExecution $jobExecution
     * @param JobInstance $jobInstance
     * @return JobResult
     */
    protected function doJob(JobInstance $jobInstance, JobExecution $jobExecution)
    {
        $jobResult = new JobResult();
        $jobResult->setSuccessful(false);

        $isTransactionRunning = $this->isTransactionRunning();
        if (!$isTransactionRunning) {
            $this->entityManager->beginTransaction();
        }

        try {
            $job = $this->batchJobRegistry->getJob($jobInstance);
            if (!$job) {
                throw new RuntimeException(sprintf('Can\'t find job "%s"', $jobInstance->getAlias()));
            }

            $job->execute($jobExecution);

            $failureExceptions = $this->collectFailureExceptions($jobExecution);

            if ($jobExecution->getStatus()->getValue() === BatchStatus::COMPLETED && !$failureExceptions) {
                if (!$isTransactionRunning) {
                    $this->entityManager->commit();
                }
                $jobResult->setSuccessful(true);
            } else {
                if (!$isTransactionRunning) {
                    $this->entityManager->rollback();
                }
                foreach ($failureExceptions as $failureException) {
                    $jobResult->addFailureException($failureException);
                }
            }

            // trigger save of JobExecution and JobInstance
            $this->batchJobRepository->getJobManager()->flush();
            $this->batchJobRepository->getJobManager()->clear();
        } catch (\Exception $exception) {
            if (!$isTransactionRunning) {
                $this->entityManager->rollback();
            }
            $jobExecution->addFailureException($exception);
            $jobResult->addFailureException($exception->getMessage());

            $this->saveFailedJobExecution($jobExecution);
        }

        return $jobResult;
    }

    /**
     * @return bool
     */
    protected function isTransactionRunning()
    {
        return $this->entityManager->getConnection()->getTransactionNestingLevel() !== 0;
    }

    /**
     * Try to save batch entities only in case when it's possible
     *
     * @param JobExecution $jobExecution
     */
    protected function saveFailedJobExecution(JobExecution $jobExecution)
    {
        $batchManager = $this->batchJobRepository->getJobManager();
        $batchUow     = $batchManager->getUnitOfWork();
        $couldBeSaved = $batchManager->isOpen()
            && $batchUow->getEntityState($jobExecution) === UnitOfWork::STATE_MANAGED;

        if ($couldBeSaved) {
            $batchManager->flush();
        }
    }

    /**
     * @param string $jobCode
     * @return array
     */
    public function getJobErrors($jobCode)
    {
        return $this->collectErrors($this->getJobExecutionByJobInstanceCode($jobCode));
    }

    /**
     * @param string $jobCode
     * @return array
     */
    public function getJobFailureExceptions($jobCode)
    {
        return $this->collectFailureExceptions($this->getJobExecutionByJobInstanceCode($jobCode));
    }

    /**
     * @param string $jobCode
     * @return JobExecution
     * @throws LogicException
     */
    protected function getJobExecutionByJobInstanceCode($jobCode)
    {
        /** @var JobInstance $jobInstance */
        $jobInstance = $this->getJobInstanceRepository()->findOneBy(['code' => $jobCode]);
        if (!$jobInstance) {
            throw new LogicException(sprintf('No job instance found with code %s', $jobCode));
        }

        /** @var JobExecution $jobExecution */
        $jobExecution = $jobInstance->getJobExecutions()->first();
        if (!$jobExecution) {
            throw new LogicException(sprintf('No job execution found for job instance with code %s', $jobCode));
        }

        return $jobExecution;
    }

    /**
     * @return EntityRepository
     */
    protected function getJobInstanceRepository()
    {
        return $this->managerRegistry->getRepository('AkeneoBatchBundle:JobInstance');
    }

    /**
     * @param JobExecution $jobExecution
     * @return array
     */
    protected function collectFailureExceptions(JobExecution $jobExecution)
    {
        $failureExceptions = [];
        foreach ($jobExecution->getAllFailureExceptions() as $exceptionData) {
            if (!empty($exceptionData['message'])) {
                $failureExceptions[] = $exceptionData['message'];
            }
        }

        return $failureExceptions;
    }

    /**
     * @param JobExecution $jobExecution
     * @return array
     */
    protected function collectErrors(JobExecution $jobExecution)
    {
        $errors = [];
        foreach ($jobExecution->getStepExecutions() as $stepExecution) {
            $errors = array_merge(
                $errors,
                $this->contextRegistry->getByStepExecution($stepExecution)->getErrors()
            );
        }

        return $errors;
    }

    /**
     * @param string $prefix
     * @return string
     */
    protected function generateJobCode($prefix = '')
    {
        if ($prefix) {
            $prefix .= '_';
        }

        $prefix .= date('Y_m_d_H_i_s') . '_';

        return preg_replace('~\W~', '_', uniqid($prefix, true));
    }

    /**
     * Initialize environment
     */
    protected function initialize()
    {
        $this->entityManager = $this->managerRegistry->getManager();
    }
}
