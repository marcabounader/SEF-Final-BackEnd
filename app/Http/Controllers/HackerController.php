<?php

namespace App\Http\Controllers;

use App\Models\ActiveLab;
use App\Models\CompletedLab;
use App\Models\Lab;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class HackerController extends Controller
{
    private function getPortNumberFromContainer($containerName)
    {
        $command = "docker inspect --format=\"{{(index (index .NetworkSettings.Ports \\\"80/tcp\\\") 0).HostPort}}\" $containerName 2>&1";
        exec($command, $output, $exitCode);
        if ($exitCode === 0) {
            return (int)$output[0];
        }else {
            // Save the error output to a file for debugging
            $errorLogFile = storage_path("logs/docker-errors.log");
            file_put_contents($errorLogFile, "Error executing Docker inspect command:\n");
            file_put_contents($errorLogFile, $command . "\n", FILE_APPEND);
            file_put_contents($errorLogFile, implode("\n", $output) . "\n", FILE_APPEND);
    
            return null; // Port number not found
        }
    }
    public function runSqliForUser(Request $request)
    {
        $user_id=Auth::id();
        $lab_id=$request->lab_id;
        $project_name = "mutillidae_sqli_{$user_id}";
        
        $userDockerDir = storage_path("mutillidae-docker-master/user-instances/$user_id");
        if (!file_exists($userDockerDir)) {
            mkdir($userDockerDir, 0755, true);
        }

        $dockerComposeFile = "$userDockerDir/docker-compose.yml";
        $randomFlag = Str::random(20);

        $dockerComposeContent = "
        # Documentation: https://github.com/compose-spec/compose-spec/blob/master/spec.md
        # Purpose: Build local containers for the Mutillidae environment
        
        version: '3.7'
        services:
        
          database:
            container_name: database-$user_id
            image: webpwnized/mutillidae:database
            build: 
                context: ./database
                dockerfile: Dockerfile
            networks:
              - datanet   
            stop_grace_period: 1m
        
          database_admin:
            container_name: database_admin-$user_id
            depends_on:
              - database
            image: webpwnized/mutillidae:database_admin
            build:
                context: ./database_admin
                dockerfile: Dockerfile
            ports:
              - 127.0.0.1::80
            networks:
              - datanet   
            stop_grace_period: 1m
        
          directory:
            container_name: directory-$user_id
            image: webpwnized/mutillidae:ldap
            build:
                context: ./ldap
                dockerfile: Dockerfile
            volumes:
              - ldap_data:/var/lib/ldap
              - ldap_config:/etc/ldap/slapd.d
            ports:
              - 127.0.0.1::389
            networks:
              - ldapnet
            stop_grace_period: 1h
        
          directory_admin:
            container_name: directory_admin-$user_id
            depends_on:
              - directory
            image: webpwnized/mutillidae:ldap_admin
            build:
                context: ./ldap_admin          
                dockerfile: Dockerfile
            ports:
              - 127.0.0.1::80
            networks:
              - ldapnet
            stop_grace_period: 1h
              
          www-sqli:
              container_name: www-sqli-$user_id
              depends_on:
                - database
                - directory
              image: webpwnized/mutillidae:www-sqli
              build:
                  context: ../../../../storage/mutillidae-docker-master/www-sqli
                  dockerfile: Dockerfile
              ports:
                - 127.0.0.1::80
                - 127.0.0.1::443
              networks:
                - datanet
                - ldapnet
              environment:
              - FLAG= flag-{$randomFlag}
              stop_grace_period: 1h
        # Volumes to persist data used by the LDAP server
        volumes:
          ldap_data:
          ldap_config:
          
        # Create network segments for the containers to use
        networks:
            datanet:
            ldapnet:        
        ";

        file_put_contents($dockerComposeFile,$dockerComposeContent);
        //build command
        $command = "docker-compose -f $dockerComposeFile -p $project_name up -d 2>&1";
        exec($command, $output, $exitCode);

        
        // Check the exit code to determine if the command was successful
        if ($exitCode === 0) {
            $containerName="www-sqli-$user_id";
            $portNumber = $this->getPortNumberFromContainer($containerName);

            //Add active lab
            $active = ActiveLab::create([
                'user_id' => Auth::id(),
                'lab_id' => $lab_id,
                'flag' => $randomFlag,
                'project_name' => $project_name,
                'port' => $portNumber
            ]);

            return response()->json([
                'message' => "Instance started for user ID {$user_id}",
                'port_number' => $portNumber,
                'active_lab' => $active,
                'output' => $output, // Capture the command output
            ]);
        } else {
            // The command encountered an error
            return response()->json([
                'message' => "Error starting instance for user ID {$user_id}",
                'output' => $output, // Capture the command output
            ]);
        }
    }
    
    public function stopUserLab($project_name)
    {
        $user_id = Auth::id();
        $project_name = "{$project_name}_{$user_id}";
        // Path to the user's Docker Compose file
        $userDockerDir = storage_path("mutillidae-docker-master/user-instances/$user_id");
        $dockerComposeFile = "$userDockerDir/docker-compose.yml";
    
        // Stop and remove containers using docker-compose
        $command = "docker-compose -f $dockerComposeFile -p $project_name down 2>&1";
        exec($command, $output, $exitCode);
    
        // Check the exit code to determine if the command was successful
        if ($exitCode === 0) {
            // Find the associated ActiveLab instance by project_name
            $activeLab = ActiveLab::where('project_name', $project_name)->first();
    
            if (!$activeLab) {
                return response()->json([
                    'message' => 'Lab instance not found'
                ], 404);
            }
    
            // Delete the found ActiveLab instance
            if ($activeLab->delete()) {
                return response()->json([
                    'message' => "Lab instance stopped for user ID {$user_id}",
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Failed to delete lab instance'
                ], 500);
            }
        } else {
            // The command encountered an error
            return response()->json([
                'message' => "Error stopping instance for user ID {$user_id}",
                'output' => $output, // Capture the command output
            ]);
        }
    }

    public function getActiveLabs()
    {
        try {
            $user_id = Auth::id();
            $active_labs = ActiveLab::where("user_id", $user_id)
            ->with('activeLabInfo') // Select specific columns from activeLabInfo
            ->get(['id','lab_id', 'project_name', 'launch_time' ,'port']);
            
            if ($active_labs->isEmpty()) {
                return response()->json([
                    'message' => 'No active labs'
                ], 404);
            } else {
                $active_labs = $active_labs->map(function ($item) {
                    $item->id=$item->lab_id;
                    $item->category_id = $item->activeLabInfo->category_id;
                    $item->difficulty_id = $item->activeLabInfo->difficulty_id;
                    $item->name = $item->activeLabInfo->name;
                    $item->objective = $item->activeLabInfo->objective;
                    $item->score = $item->activeLabInfo->score;

                    unset($item->activeLabInfo); // Remove the activeLabInfo key
                    return $item;
                });
                return response()->json([
                    'message' => 'Active labs found',
                    "active_labs" => $active_labs
                ], 200);
            }
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function submitFlag(Request $request)
    {
        try {
            $user_id = Auth::id();
            $user = Auth::user();
            $submitted_flag = $request->flag;
            $id = $request->id;
            $userDockerDir = storage_path("mutillidae-docker-master/user-instances/$user_id");
            $dockerComposeFile = "$userDockerDir/docker-compose.yml";
            
            $active_lab = ActiveLab::where([
                ["lab_id", '=', $id],
                ["flag", '=', $submitted_flag],
                ["user_id", '=', $user_id]
            ])->first();
            
            if (!$active_lab) {
                return response()->json([
                    'message' => 'Flag incorrect'
                ], 404);
            } else {


                $completed_lab = CompletedLab::create([
                    'user_id' => Auth::id(),
                    'lab_id' => $id
                ]);
                $project_name=$active_lab->project_name;
                $command = "docker-compose -f $dockerComposeFile -p $project_name down 2>&1";
                exec($command, $output, $exitCode);

                $lab = Lab::find($id); // Use find instead of where to retrieve a single lab by its primary key
    
                // Check if the lab was found
                if (!$lab) {
                    return response()->json([
                        'message' => 'Lab not found'
                    ], 404);
                }
    
                $new_score = $user->score + $lab->score; // Calculate the new score
    
                // Update the user's score
                $user->update(['score' => $new_score]);
    
                return response()->json([
                    'message' => 'Flag is correct',
                    'completed_lab' => $lab
                ], 200);
            }
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    
}
