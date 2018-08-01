<?php

require_once 'models/sftp.php';
require_once 'resources/sshConf.php';

class IndexController{

    private $conn;
    private $db;
    private $sessions;
    private $sshConf;

    # DB values
    private $contents;

    # Values that will be fetched from the view
    private $viewInputValues;
    private $error;

    # Various models
    private $ftp;

    public function __construct(Connection $conn, Crud $db, Session $sessions){
        $this->conn     = $conn;
        $this->db       = $db;
        $this->sessions = $sessions;

        # SSH keys and server IP
        $this->sshConf  = new sshConf();
        $this->ftp      = new Sftp($this->sshConf->get()['ip']);
        $this->ftp->Keys($this->sshConf->get()['pub'],
                         $this->sshConf->get()['priv']);

        # Fetch db values
        $select         = ['*' => 'examples'];
        $this->contents = $this->db->read($select);
        if (!$this->contents)
            $this->contents = array(); # Empty array in case db is empty

        # Set view values
        $this->viewInputValues = array();
        self::setViewInputs();
        self::checkErrors();
    }

    /**
     * Upon failed attempts, the inputs are saved and here.
     * When editing an example, the values from the db will
     * also be fetched here.
     * They'll be set for the view to collect
     * @return     Sets $viewInputValues
     */
    private function setViewInputs(){

        $this->viewInputValues['headline'] = "";
        $this->viewInputValues['content']  = "";

        if($this->sessions->isset('headline')) {
            $this->viewInputValues['headline'] = $this->sessions->get('headline');
            $this->sessions->unset('headline');
        }

        if ($this->sessions->isset('content')) {
            $this->viewInputValues['content'] = $this->sessions->get('content');
            $this->sessions->unset('content');
        }

        if (isset($_GET['edit'])
            && is_numeric($_GET['edit'])
            && !empty($this->contents[$_GET['edit']])) {
                $id = $_GET['edit'];
                $this->viewInputValues = ['headline'  => $this->contents[$id]['headline'],
                                          'content'   => $this->contents[$id]['content']];
        }
    }

    /**
     * Return any input values to the view.
     * @return array Input values
     */
    public function getViewInputs(){
        return $this->viewInputValues;
    }

    /**
     * Adds a new example to the database
     */
    public function addExample(){
        $headline    = $_POST['headline'];
        $content     = $_POST['content'];

        # Check for empty values (requirements)
        if(empty($headline))
            $this->sessions->set('error','Missing headline');
        if(empty($content))
            $this->sessions->set('error','Missing content');

        # Store the values in case of error
        if (!empty($content))
            $this->sessions->set('content',$content);
        if (!empty($headline))
            $this->sessions->set('headline',$headline);

        # Insert example to db
        if(!$this->sessions->isset('error')){

            # Transfer model to server
            try {
                $file = $_FILES['file'];
                $this->ftp->connect('stick');
                $this->ftp->sendFile($file['tmp_name'], '/var/www/html/models/'.$file['name']);
            }catch(Exception $e){
                $this->sessions->set('error', $e.getMessage());
            }

            # Insert the preview
            try {
                $values = ['headline'      => $headline,
                           'content'       => $content,
                           'file_location' => '/var/www/html/models/'.$file['name']];

                if($this->db->create('examples', $values)){
                    $this->sessions->unset('headline');
                    $this->sessions->unset('content');
                }
            } catch (Exception $e) {
                $this->sessions->set('error', $e.getMessage());
            }
        }

        header("location:index.php");
    }

    /**
     * Updates an example in the database
     * @param  int    $id The id of which example to update
     * @return        Redirects back to CMS page
     */
    public function editExample(int $id){
        if(empty($this->contents[$id])){
            header("location:index.php");
            exit;
        }

        if(empty($_POST['headline']) || empty($_POST['content'])){
            $this->sessions->set('error', 'Headline and Content both needs values.');
            header("location:index.php");
            exit;
        }

        # Update content (db)
        try {
            $table = "examples";
            $data  = ['headline' => $_POST['headline'],
                      'content'  => $_POST['content']];
            $where = ['id' => $id];

            if ($this->db->update($table, $data, $where))
                $this->sessions->set('message', 'Example updated');

        } catch (Exception $e) {
            $this->sessions->set('error', $e.getMessage());
        }

        # Update file (if any)
        if ($_FILES['file']['size'] != 0) {
            try {
                $file = $_FILES['file'];
                $this->ftp->connect('stick');
                $this->ftp->sendFile($file['tmp_name'], '/var/www/html/models/'.$file['name']);
            }catch(Exception $e){
                $this->sessions->set('error', $e.getMessage());
            }
        }

        header("location:index.php");
    }

    /**
     * Returns all the examples
     * @return array   Examples, fetched from the db
     */
    public function getExamples(){
        return $this->contents;
    }

    /**
     * Checks, sets and unsets errors (from session to variable)
     * @return      Sets $error
     */
    private function checkErrors(){
        if($this->sessions->isset('error')){
            $this->error = $this->sessions->get('error');
            $this->sessions->unset('error');
        }
    }

    /**
     * Returns the error (if any) to the view
     * @return string Error
     */
    public function getError(){
        return $this->error;
    }

    /**
     * Fetches all the files (modules) from the PhinitPreview server
     */
    public function getModules() {
        try{
            $this->ftp->connect('stick');
            return $this->ftp->scanDir('/var/www/html/models/');
        }catch(Exception $e){
            $this->sessions->set('error',$e->getMessage());
        }
    }

    /**
     * Deletes an example from the database
     * @return    Redirects back to index
     */
    public function deleteExample() {
        $id = $_POST['id'];
        $headline = $_POST['headline'];
        if (!empty($this->contents[$id])){
            try{
                $table = "examples";
                $where = ['id' => $id];
                if ($this->db->delete($table, $where))
                    $this->sessions->set('message', 'Deleted '.$headline);
            }catch(Exception $e){
                $this->sessions->set('error', $e->getMessage());
            }
        }

        header("location:index.php");
    }

    /**
     * Deletes one of the uploaded classes
     * @return     Redirects back
     */
    public function deleteFile(){
        try{
            $this->ftp->connect('stick');
            if($this->ftp->removeFile('/var/www/html/models/'.$_POST['file']))
                $this->sessions->set('message', 'Removed '.$_POST['file']);
        }catch(Exception $e){
            $this->sessions->set('error',$e->getMessage());
        }

        header("location:index.php?modules");
    }
}

?>
