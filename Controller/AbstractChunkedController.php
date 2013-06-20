<?php

namespace Oneup\UploaderBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use Oneup\UploaderBundle\UploadEvents;
use Oneup\UploaderBundle\Uploader\Response\ResponseInterface;
use Oneup\UploaderBundle\Controller\AbstractController;
use Oneup\UploaderBundle\Event\PostChunkUploadEvent;

abstract class AbstractChunkedController extends AbstractController
{
    /**
     *  Parses a chunked request and return relevant information.
     *
     *  This function must return an array containing the following
     *  keys and their corresponding values:
     *    - last: Wheter this is the last chunk of the uploaded file
     *    - uuid: A unique id which distinguishes two uploaded files
     *            This uuid must stay the same among the task of
     *            uploading a chunked file.
     *    - index: A numerical representation of the currently uploaded
     *            chunk. Must be higher that in the previous request.
     *    - orig: The original file name.
     *
     *  @param request The request object
     *  @return array
     */
    abstract protected function parseChunkedRequest(Request $request);
    
    /**
     *  This function will be called in order to upload and save an
     *  uploaded chunk.
     *
     *  This function also calls the chunk manager if the function
     *  parseChunkedRequest has set true for the "last" key of the
     *  returned array to reassemble the uploaded chunks.
     *
     *  @param file The uploaded chunk.
     *  @param response A response object.
     *  @param request The request object.
     */
    protected function handleChunkedUpload(UploadedFile $file, ResponseInterface $response, Request $request)
    {
        // get basic container stuff
        $request = $this->container->get('request');
        $chunkManager = $this->container->get('oneup_uploader.chunk_manager');
        
        // reset uploaded to always have a return value
        $uploaded = null;
        
        // get information about this chunked request
        list($last, $uuid, $index, $orig) = $this->parseChunkedRequest($request);
        
        $chunk = $chunkManager->addChunk($uuid, $index, $file, $orig);
        
        $this->dispatchChunkEvents($chunk, $response, $request, $last);
        
        // if all chunks collected and stored, proceed
        // with reassembling the parts
        if ($last) {
            // we'll take the first chunk and append the others to it
            // this way we don't need another file in temporary space for assembling
            $chunks = $chunkManager->getChunks($uuid);
            
            // assemble parts
            $assembled = $chunkManager->assembleChunks($chunks);
            $path = $assembled->getPath();
            
            // create a temporary uploaded file to meet the interface restrictions
            $uploadedFile = new UploadedFile($assembled->getPathname(), $assembled->getBasename(), null, null, null, true);
            
            // validate this entity and upload on success
            $this->validate($uploadedFile);
            $uploaded = $this->handleUpload($uploadedFile, $response, $request);
            
            $chunkManager->cleanup($path);
        }
    }

    /**
     *  This function is a helper function which dispatches post chunk upload event.
     *
     *  @param uploaded The uploaded chunk.
     *  @param response A response object.
     *  @param request The request object.
     *  @param isLast True if this is the last chunk, false otherwise.
     */
    protected function dispatchChunkEvents($uploaded, ResponseInterface $response, Request $request, $isLast)
    {
        $dispatcher = $this->container->get('event_dispatcher');
        
        // dispatch post upload event (both the specific and the general)
        $postUploadEvent = new PostChunkUploadEvent($uploaded, $response, $request, $isLast, $this->type, $this->config);
        $dispatcher->dispatch(UploadEvents::POST_CHUNK_UPLOAD, $postUploadEvent);
        $dispatcher->dispatch(sprintf('%s.%s', UploadEvents::POST_CHUNK_UPLOAD, $this->type), $postUploadEvent);
    }
}