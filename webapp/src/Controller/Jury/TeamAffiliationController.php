<?php declare(strict_types=1);

namespace App\Controller\Jury;

use App\Controller\BaseController;
use App\Entity\TeamAffiliation;
use App\Form\Type\TeamAffiliationType;
use App\Service\AssetUpdateService;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\ScoreboardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_JURY')]
#[Route(path: '/jury/affiliations')]
class TeamAffiliationController extends BaseController
{
    public function __construct(
        EntityManagerInterface $em,
        DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        KernelInterface $kernel,
        protected readonly EventLogService $eventLogService,
        protected readonly AssetUpdateService $assetUpdater,
    ) {
        parent::__construct($em, $eventLogService, $dj, $kernel);
    }

    #[Route(path: '', name: 'jury_team_affiliations')]
    public function indexAction(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir
    ): Response {
        $em               = $this->em;
        $teamAffiliations = $em->createQueryBuilder()
            ->select('a', 'COUNT(t.teamid) AS num_teams')
            ->from(TeamAffiliation::class, 'a')
            ->leftJoin('a.teams', 't')
            ->orderBy('a.name', 'ASC')
            ->groupBy('a.affilid')
            ->getQuery()->getResult();

        $showFlags = $this->config->get('show_flags');

        $table_fields = [
            'affilid' => ['title' => 'ID', 'sort' => true],
            'externalid' => ['title' => 'external ID', 'sort' => true],
            'icpcid' => ['title' => 'ICPC ID', 'sort' => true],
            'shortname' => ['title' => 'shortname', 'sort' => true],
            'name' => ['title' => 'name', 'sort' => true, 'default_sort' => true],
        ];

        if ($showFlags) {
            $table_fields['country'] = ['title' => 'country', 'sort' => true];
            $table_fields['affiliation_logo'] = ['title' => 'logo', 'sort' => false];
        }

        $table_fields['num_teams'] = ['title' => '# teams', 'sort' => true];

        $propertyAccessor        = PropertyAccess::createPropertyAccessor();
        $team_affiliations_table = [];
        foreach ($teamAffiliations as $teamAffiliationData) {
            /** @var TeamAffiliation $teamAffiliation */
            $teamAffiliation    = $teamAffiliationData[0];
            $affiliationdata    = [];
            $affiliationactions = [];
            // Get whatever fields we can from the affiliation object itself.
            foreach ($table_fields as $k => $v) {
                if ($propertyAccessor->isReadable($teamAffiliation, $k)) {
                    $affiliationdata[$k] = ['value' => $propertyAccessor->getValue($teamAffiliation, $k)];
                }
            }

            if ($this->isGranted('ROLE_ADMIN')) {
                $affiliationactions[] = [
                    'icon' => 'edit',
                    'title' => 'edit this affiliation',
                    'link' => $this->generateUrl('jury_team_affiliation_edit', [
                        'affilId' => $teamAffiliation->getAffilid(),
                    ])
                ];
                $affiliationactions[] = [
                    'icon' => 'trash-alt',
                    'title' => 'delete this affiliation',
                    'link' => $this->generateUrl('jury_team_affiliation_delete', [
                        'affilId' => $teamAffiliation->getAffilid(),
                    ]),
                    'ajaxModal' => true,
                ];
            }

            $affiliationdata['num_teams'] = ['value' => $teamAffiliationData['num_teams']];
            if ($showFlags) {
                $countryCode     = $teamAffiliation->getCountry();
                $affiliationdata['country'] = [
                    'value' => $countryCode,
                    'sortvalue' => $countryCode,
                ];
            }

            $affiliationdata['affiliation_logo'] = [
                'value' => $teamAffiliation->getExternalid() ?? $teamAffiliation->getAffilid(),
                'title' => $teamAffiliation->getShortname(),
            ];

            $team_affiliations_table[] = [
                'data' => $affiliationdata,
                'actions' => $affiliationactions,
                'link' => $this->generateUrl('jury_team_affiliation', ['affilId' => $teamAffiliation->getAffilid()]),
            ];
        }

        return $this->render('jury/team_affiliations.html.twig', [
            'team_affiliations' => $team_affiliations_table,
            'table_fields' => $table_fields,
        ]);
    }

    #[Route(path: '/{affilId<\d+>}', name: 'jury_team_affiliation')]
    public function viewAction(Request $request, ScoreboardService $scoreboardService, int $affilId): Response
    {
        $teamAffiliation = $this->em->getRepository(TeamAffiliation::class)->find($affilId);
        if (!$teamAffiliation) {
            throw new NotFoundHttpException(sprintf('Team affiliation with ID %s not found', $affilId));
        }

        $data = [
            'teamAffiliation' => $teamAffiliation,
            'showFlags' => $this->config->get('show_flags'),
            'refresh' => [
                'after' => 30,
                'url' => $this->generateUrl('jury_team_affiliation', ['affilId' => $teamAffiliation->getAffilid()]),
                'ajax' => true,
            ],
            'maxWidth' => $this->config->get('team_column_width'),
            'public' => false,
        ];

        if ($currentContest = $this->dj->getCurrentContest()) {
            $data = array_merge(
                $data,
                $scoreboardService->getScoreboardTwigData(
                    $request, null, '', true, false, false, $currentContest
                )
            );
            $data['limitToTeams'] = $teamAffiliation->getTeams();
        }

        // For ajax requests, only return the submission list partial.
        if ($request->isXmlHttpRequest()) {
            $data['displayRank'] = true;
            return $this->render('partials/scoreboard_table.html.twig', $data);
        }

        return $this->render('jury/team_affiliation.html.twig', $data);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{affilId<\d+>}/edit', name: 'jury_team_affiliation_edit')]
    public function editAction(Request $request, int $affilId): Response
    {
        $teamAffiliation = $this->em->getRepository(TeamAffiliation::class)->find($affilId);
        if (!$teamAffiliation) {
            throw new NotFoundHttpException(sprintf('Team affiliation with ID %s not found', $affilId));
        }

        $form = $this->createForm(TeamAffiliationType::class, $teamAffiliation);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assetUpdater->updateAssets($teamAffiliation);
            $this->saveEntity($teamAffiliation, $teamAffiliation->getAffilid(), false);
            return $this->redirectToRoute('jury_team_affiliation', ['affilId' => $teamAffiliation->getAffilid()]);
        }

        return $this->render('jury/team_affiliation_edit.html.twig', [
            'teamAffiliation' => $teamAffiliation,
            'form' => $form,
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/{affilId<\d+>}/delete', name: 'jury_team_affiliation_delete')]
    public function deleteAction(Request $request, int $affilId): Response
    {
        $teamAffiliation = $this->em->getRepository(TeamAffiliation::class)->find($affilId);
        if (!$teamAffiliation) {
            throw new NotFoundHttpException(sprintf('Team affiliation with ID %s not found', $affilId));
        }

        return $this->deleteEntities($request, [$teamAffiliation], $this->generateUrl('jury_team_affiliations'));
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route(path: '/add', name: 'jury_team_affiliation_add')]
    public function addAction(Request $request): Response
    {
        $teamAffiliation = new TeamAffiliation();

        $form = $this->createForm(TeamAffiliationType::class, $teamAffiliation);

        $form->handleRequest($request);

        if ($response = $this->processAddFormForExternalIdEntity(
            $form, $teamAffiliation,
            fn() => $this->generateUrl('jury_team_affiliation', ['affilId' => $teamAffiliation->getAffilid()]),
            function () use ($teamAffiliation) {
                $this->em->persist($teamAffiliation);
                $this->assetUpdater->updateAssets($teamAffiliation);
                $this->saveEntity($teamAffiliation, null, true);
                return null;
            }
        )) {
            return $response;
        }

        return $this->render('jury/team_affiliation_add.html.twig', [
            'form' => $form,
        ]);
    }
}
