<?php declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Language;
use App\Entity\Problem;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class SubmitProblemType extends AbstractType
{
    public function __construct(
        protected readonly DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly EntityManagerInterface $em
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $allowMultipleFiles = $this->config->get('sourcefiles_limit') > 1;
        $user               = $this->dj->getUser();
        $contest            = $this->dj->getCurrentContest($user->getTeam()->getTeamid());

        $builder->add('code', FileType::class, [
            'label' => 'Source file' . ($allowMultipleFiles ? 's' : ''),
            'multiple' => $allowMultipleFiles,
        ]);

        $problemConfig = [
            'class' => Problem::class,
            'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('p')
                ->join('p.contest_problems', 'cp', Join::WITH, 'cp.contest = :contest')
                ->select('p', 'cp')
                ->andWhere('cp.allowSubmit = 1')
                ->setParameter('contest', $contest)
                ->addOrderBy('cp.shortname'),
            'choice_label' => fn(Problem $problem) => sprintf(
                '%s - %s', $problem->getContestProblems()->first()->getShortName(), $problem->getName()
            ),
            'placeholder' => 'Select a problem',
        ];
        $builder->add('problem', EntityType::class, $problemConfig);

        $languages = $this->dj->getAllowedLanguagesForContest($contest);
        $builder->add('language', EntityType::class, [
            'class' => Language::class,
            'choices' => $languages,
            'choice_label' => 'name',
            'placeholder' => 'Select a language',
        ]);

        $builder->add('entry_point', TextType::class, [
            'label' => 'Entry point',
            'required' => false,
            'help' => 'The entry point for your code.',
            'row_attr' => ['data-entry-point' => ''],
            'constraints' => [
                new Callback(function ($value, ExecutionContextInterface $context) {
                    /** @var Form $form */
                    $form = $context->getRoot();
                    /** @var Language $language */
                    $language = $form->get('language')->getData();
                    if ($language->getRequireEntryPoint() && empty($value)) {
                        $message = sprintf('%s required, but not specified',
                                           $language->getEntryPointDescription() ?: 'Entry point');
                        $context
                            ->buildViolation($message)
                            ->atPath('entry_point')
                            ->addViolation();
                    }
                }),
            ]
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($problemConfig) {
            if (isset($event->getData()['problem'])) {
                $problemConfig += ['row_attr' => ['class' => 'd-none']];
                $event->getForm()->add('problem', EntityType::class, $problemConfig);
            }
        });
    }
}
