<?php

namespace App\Controller;

use App\Entity\LessonTopic;
use App\Entity\LessonAttachment;
use App\Entity\LessonQuiz;
use App\Entity\Schedule;
use App\Form\LessonContentType;
use App\Form\LessonAttachmentType;
use App\Security\LessonVoter;
use App\Security\UserRole;
use App\Service\LessonFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LessonController extends AbstractController
{
    #[Route('/lessons', name: 'app_lessons', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $manager): Response
    {
        $actor = $this->getUser();
        if (!in_array($actor->getRole(), [UserRole::Teacher, UserRole::Student, UserRole::HeadTeacher, UserRole::Admin], true)) throw $this->createAccessDeniedException();
        $query = $manager->createQueryBuilder()->select('l', 'd', 't')->from(LessonTopic::class, 'l')
            ->join('l.discipline', 'd')->leftJoin('d.teachers', 't')->orderBy('d.name', 'ASC')->addOrderBy('l.id', 'DESC');
        if ($actor->getRole() === UserRole::Teacher) $query->andWhere(':actor MEMBER OF d.teachers')->setParameter('actor', $actor);
        if ($actor->getRole() === UserRole::Student) {
            if (!$actor->getStudentGroup()) {
                $query->andWhere('1 = 0');
            } else {
                $query->andWhere(':group MEMBER OF d.groups')->andWhere('EXISTS (SELECT s.id FROM '.Schedule::class.' s WHERE s.topic = l AND s.group = :group AND s.discipline = d)')->setParameter('group', $actor->getStudentGroup());
            }
        }
        $paginator = new Paginator($query->getQuery(), fetchJoinCollection: true);
        $total = count($paginator); $pages = max(1, (int) ceil($total / 20)); $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $paginator->getQuery()->setFirstResult(($page - 1) * 20)->setMaxResults(20);

        return $this->render('lesson/index.html.twig', ['lessons' => $paginator, 'total' => $total, 'pages' => $pages, 'page' => $page]);
    }

    #[Route('/lessons/{id}', name: 'app_lesson_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $manager): Response
    {
        $lesson = $this->lesson($id, $manager, LessonVoter::VIEW);
        $quizFilter = ['lesson' => $lesson];
        if ($this->getUser()->getRole() === UserRole::Student) $quizFilter['published'] = true;

        return $this->render('lesson/show.html.twig', [
            'lesson' => $lesson,
            'materials' => $manager->getRepository(LessonAttachment::class)->findBy(['lesson' => $lesson], ['id' => 'ASC']),
            'quizzes' => $manager->getRepository(LessonQuiz::class)->findBy($quizFilter, ['id' => 'ASC']),
        ]);
    }

    #[Route('/lessons/{id}/edit', name: 'app_lesson_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $manager): Response
    {
        $lesson = $this->lesson($id, $manager, LessonVoter::EDIT);
        $form = $this->createForm(LessonContentType::class, $lesson);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->flush();
            $this->addFlash('success', 'Заняття оновлено.');
            return $this->redirectToRoute('app_lesson_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('lesson/edit.html.twig', ['lesson' => $lesson, 'lessonForm' => $form]);
    }

    #[Route('/lessons/{id}/materials/new', name: 'app_lesson_material_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/lesson-materials/{materialId}/edit', name: 'app_lesson_material_edit', requirements: ['materialId' => '\d+'], methods: ['GET', 'POST'])]
    public function material(Request $request, EntityManagerInterface $manager, LessonFileStorage $files, ?int $id = null, ?int $materialId = null): Response
    {
        if ($materialId !== null) {
            $material = $manager->find(LessonAttachment::class, $materialId) ?? throw $this->createNotFoundException();
            $lesson = $material->getLesson(); $this->denyAccessUnlessGranted(LessonVoter::EDIT, $lesson);
        } else {
            $lesson = $this->lesson($id, $manager, LessonVoter::EDIT);
            $material = (new LessonAttachment())->setLesson($lesson);
        }
        $oldFile = $material->getStorageName();
        $form = $this->createForm(LessonAttachmentType::class, $material);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $upload = $form->get('upload')->getData();
            if ($material->getKind() === 'file' && !$upload && !$oldFile) {
                $form->get('upload')->addError(new FormError('Оберіть файл.'));
            } else {
                $newFile = null;
                try {
                    if ($material->getKind() === 'file' && $upload) {
                        $newFile = $files->store($upload);
                        $material->setStorageName($newFile)->setOriginalName(mb_substr(basename(str_replace('\\', '/', $upload->getClientOriginalName())), 0, 255));
                    } elseif ($material->getKind() !== 'file') {
                        $material->setStorageName(null)->setOriginalName(null);
                    }
                    $manager->persist($material); $manager->flush();
                } catch (\Throwable $error) {
                    $files->remove($newFile);
                    throw $error;
                }
                if ($oldFile !== $material->getStorageName()) $files->remove($oldFile);
                $this->addFlash('success', 'Матеріал збережено.');
                return $this->redirectToRoute('app_lesson_show', ['id' => $lesson->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('lesson/material_form.html.twig', ['lesson' => $lesson, 'material' => $material, 'materialForm' => $form]);
    }

    #[Route('/lesson-materials/{id}/download', name: 'app_lesson_material_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id, EntityManagerInterface $manager, LessonFileStorage $files): Response
    {
        $material = $manager->find(LessonAttachment::class, $id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(LessonVoter::VIEW, $material->getLesson());
        if ($material->getKind() !== 'file' || !$material->getStorageName() || !is_file($path = $files->path($material->getStorageName()))) throw $this->createNotFoundException();
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $material->getOriginalName() ?? 'material', 'material');

        return $response;
    }

    #[Route('/lesson-materials/{id}/delete', name: 'app_lesson_material_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteMaterial(int $id, Request $request, EntityManagerInterface $manager, LessonFileStorage $files): Response
    {
        $material = $manager->find(LessonAttachment::class, $id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(LessonVoter::EDIT, $material->getLesson());
        if (!$this->isCsrfTokenValid('delete_material_'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $lessonId = $material->getLesson()->getId(); $filename = $material->getStorageName();
        $manager->remove($material); $manager->flush(); $files->remove($filename);

        return $this->redirectToRoute('app_lesson_show', ['id' => $lessonId], Response::HTTP_SEE_OTHER);
    }

    private function lesson(int $id, EntityManagerInterface $manager, string $permission): LessonTopic
    {
        $lesson = $manager->find(LessonTopic::class, $id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted($permission, $lesson);
        return $lesson;
    }
}
