<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\MediaBundle\Controller;

use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Provider\MediaProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class MediaController extends Controller
{
    /**
     * @param MediaInterface $media
     *
     * @return MediaProviderInterface
     */
    public function getProvider(MediaInterface $media)
    {
        return $this->get('sonata.media.pool')->getProvider($media->getProviderName());
    }

    /**
     * @param string $id
     *
     * @return MediaInterface
     */
    public function getMedia($id)
    {
        return $this->get('sonata.media.manager.media')->find($id);
    }

    /**
     * @throws NotFoundHttpException
     *
     * @param Request $request
     * @param string  $id
     * @param string  $format
     *
     * @return Response
     */
    public function downloadAction(Request $request, $id, $format = MediaProviderInterface::FORMAT_REFERENCE)
    {
        $media = $this->getMedia($id);

        if (!$media) {
            throw new NotFoundHttpException(sprintf('unable to find the media with the id : %s', $id));
        }

        if (!$this->get('sonata.media.pool')->getDownloadSecurity($media)->isGranted($media, $request)) {
            throw new AccessDeniedException();
        }

        $response = $this->getProvider($media)->getDownloadResponse($media, $format, $this->get('sonata.media.pool')->getDownloadMode($media));

        if ($response instanceof BinaryFileResponse) {
            $response->prepare($request);
        }

        return $response;
    }

    /**
     * @throws NotFoundHttpException
     *
     * @param Request $request
     * @param string  $id
     * @param string  $format
     *
     * @return Response
     */
    public function viewAction(Request $request, $id, $format = MediaProviderInterface::FORMAT_REFERENCE)
    {
        $media = $this->getMedia($id);

        if (!$media) {
            throw new NotFoundHttpException(sprintf('unable to find the media with the id : %s', $id));
        }

        if (!$this->get('sonata.media.pool')->getDownloadSecurity($media)->isGranted($media, $request)) {
            throw new AccessDeniedException();
        }

        return $this->render('SonataMediaBundle:Media:view.html.twig', [
            'media' => $media,
            'formats' => $this->get('sonata.media.pool')->getFormatNamesByContext($media->getContext()),
            'format' => $format,
        ]);
    }

    /**
     * This action applies a given filter to a given image,
     * optionally saves the image and
     * outputs it to the browser at the same time.
     *
     * @param Request $request
     * @param string  $path
     * @param string  $filter
     *
     * @return Response
     */
    public function liipImagineFilterAction(Request $request, $path, $filter)
    {
        if (!preg_match('@([^/]*)/(.*)/([0-9]*)_([a-z_A-Z]*).jpg@', $path, $matches)) {
            throw new NotFoundHttpException();
        }

        $targetPath = $this->get('liip_imagine.cache.manager')->resolve($request, $path, $filter);

        if ($targetPath instanceof Response) {
            return $targetPath;
        }

        // get the file
        $media = $this->getMedia($matches[3]);
        if (!$media) {
            throw new NotFoundHttpException();
        }

        $provider = $this->getProvider($media);
        $file = $provider->getReferenceFile($media);

        // load the file content from the abstracted file system
        $tmpFile = sprintf('%s.%s', tempnam(sys_get_temp_dir(), 'sonata_media_liip_imagine'), $media->getExtension());
        file_put_contents($tmpFile, $file->getContent());

        $image = $this->get('liip_imagine')->open($tmpFile);

        $response = $this->get('liip_imagine.filter.manager')->get($request, $filter, $image, $path);

        if ($targetPath) {
            $response = $this->get('liip_imagine.cache.manager')->store($response, $targetPath, $filter);
        }

        return $response;
    }
}
