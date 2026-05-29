PATCH: class/FanUpdate.php — printBlog() fix
============================================

THE REAL BUG
------------
The comments query result is never freed inside the loop.
After the first post, $_Results contains:
  [0] => posts_result
  [1] => comments_result   ← never freed

On the second iteration, ReadRecordPaginate() → ReadRecord() → GetLastResult()
calls end($_Results), which returns comments_result, not posts_result.
mysqli_fetch_assoc(comments_result) returns false → loop exits after 1 post.

FIND this block in printBlog() (around line 390):

                if ($post->allowComments()) {
                    $query_comments =
                        'SELECT * FROM ' . $this->getOpt('comments_table')
                        . ' WHERE entry_id=' . $post->getID()
                        . ' AND approved > 0 ORDER BY added ASC';

                    $this->db->Execute($query_comments);
                    $post->addParam('num_comments', $this->db->NumRows());
                }

REPLACE WITH (add one line — the FreeResult call):

                if ($post->allowComments()) {
                    $query_comments =
                        'SELECT * FROM ' . $this->getOpt('comments_table')
                        . ' WHERE entry_id=' . $post->getID()
                        . ' AND approved > 0 ORDER BY added ASC';

                    $this->db->Execute($query_comments);
                    $post->addParam('num_comments', $this->db->NumRows());
                    $this->db->FreeResult();   // ← ADD THIS LINE
                }

That is the entire fix. One line. No other changes to FanUpdate.php are needed.

WHY SqlConnection.php CHANGE ALSO NEEDED
-----------------------------------------
The SqlConnection.php fix from the previous step (subquery COUNT) should also
be deployed — it's a real correctness bug for GROUP BY queries in the admin
panel. But it was not the cause of the 1-post visibility issue.

SUMMARY
-------
Root cause: printBlog() calls Execute($query_comments) inside a pagination
loop without freeing the result. This corrupts the result stack, causing
ReadRecord() to read from the wrong result on every iteration after the first.
Fix: $this->db->FreeResult() immediately after NumRows().
